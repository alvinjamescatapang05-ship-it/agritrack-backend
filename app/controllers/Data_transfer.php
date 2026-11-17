<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Data_transfer extends Controller
{
    private $farmerHeaders = [
        'barangayId', 'registryNumber', 'firstName', 'middleName', 'lastName',
        'suffix', 'sex', 'birthDate', 'phone', 'email', 'address', 'primaryLivelihood',
    ];

    public function export($module)
    {
        $this->call->model('Data_transfer_model');
        try {
            $rows = $this->Data_transfer_model->export_rows(Auth_context::user(), $module);
        } catch (LengthException $exception) {
            (new Api_response())->error('export_too_large', $exception->getMessage(), 422);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_export', $exception->getMessage(), 403);
        }
        (new Api_response())->success(['module' => $module, 'rows' => $rows]);
    }

    public function history()
    {
        $this->call->model('Data_transfer_model');
        (new Api_response())->success($this->Data_transfer_model->import_history(Auth_context::user()));
    }

    public function import_farmers()
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($length > 2200000) {
            (new Api_response())->error('request_too_large', 'CSV import must be smaller than 2 MB.', 413);
        }
        $body = $this->request->json();
        if (!is_array($body) || !isset($body['csv']) || !is_string($body['csv'])) {
            (new Api_response())->error('invalid_request', 'Provide CSV text and a filename.', 400);
        }
        if ($body['csv'] === '' || strlen($body['csv']) > 2000000) {
            (new Api_response())->error('invalid_csv', 'CSV must contain data and be smaller than 2 MB.', 422);
        }
        $filename = isset($body['filename']) && is_string($body['filename'])
            ? basename(str_replace('\\', '/', trim($body['filename'])))
            : 'farmer-import.csv';
        $filename = substr($filename ?: 'farmer-import.csv', 0, 255);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare CSV import.');
        }
        fwrite($stream, $body['csv']);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if (!$headers) {
            fclose($stream);
            (new Api_response())->error('invalid_csv', 'CSV must include the template header row.', 422);
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(static function ($header) {
            return trim((string) $header);
        }, $headers);
        if (count($headers) !== count($this->farmerHeaders) || count(array_unique($headers)) !== count($headers)
            || array_diff($this->farmerHeaders, $headers) || array_diff($headers, $this->farmerHeaders)) {
            fclose($stream);
            (new Api_response())->error(
                'invalid_csv_headers',
                'Use the downloaded farmer CSV template without changing its column names.',
                422
            );
        }

        $indexes = array_flip($headers);
        $rows = [];
        $lineNumber = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $lineNumber++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (count($rows) >= 500) {
                fclose($stream);
                (new Api_response())->error('too_many_rows', 'A single import is limited to 500 records.', 422);
            }
            if (count($values) !== count($headers)) {
                $rows[] = ['line' => $lineNumber, 'values' => null];
                continue;
            }
            $mapped = [];
            foreach ($this->farmerHeaders as $header) {
                $mapped[$header] = trim((string) ($values[$indexes[$header]] ?? ''));
            }
            $rows[] = ['line' => $lineNumber, 'values' => $mapped];
        }
        fclose($stream);

        if (!$rows) {
            (new Api_response())->error('empty_csv', 'The CSV file does not contain any farmer rows.', 422);
        }

        $user = Auth_context::user();
        $this->call->model('Farmer_management_model');
        $this->call->model('Data_transfer_model');
        $errors = [];
        $imported = 0;
        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            if ($values === null) {
                $errors[] = ['line' => $line, 'message' => 'The row does not have the same number of columns as the header.'];
                continue;
            }
            $validated = $this->validate_farmer_row($values, $user);
            if (isset($validated['error'])) {
                $errors[] = ['line' => $line, 'message' => $validated['error']];
                continue;
            }
            try {
                $this->Farmer_management_model->create_record($user, $validated['data']);
                $imported++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = ['line' => $line, 'message' => $exception->getMessage()];
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                $errors[] = ['line' => $line, 'message' => 'A registry number or other unique field already exists.'];
            }
        }

        $logId = $this->Data_transfer_model->record_import($user, $filename, count($rows), $imported, $errors);
        (new Api_response())->success([
            'importId' => $logId,
            'totalRows' => count($rows),
            'importedRows' => $imported,
            'errorCount' => count($errors),
            'errors' => $errors,
        ]);
    }

    private function validate_farmer_row(array $row, array $user)
    {
        $limits = [
            'registryNumber' => 80, 'firstName' => 100, 'middleName' => 100,
            'lastName' => 100, 'suffix' => 30, 'sex' => 30, 'phone' => 40,
            'email' => 255, 'address' => 500, 'primaryLivelihood' => 150,
        ];
        foreach (['firstName', 'lastName'] as $required) {
            if ($row[$required] === '') {
                return ['error' => ucfirst($required) . ' is required.'];
            }
        }
        foreach ($limits as $field => $maximum) {
            if (strlen($row[$field]) > $maximum) {
                return ['error' => ucfirst($field) . ' exceeds ' . $maximum . ' characters.'];
            }
        }
        $email = strtolower($row['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Email address is invalid.'];
        }
        $birthDate = $row['birthDate'];
        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate || $birthDate > gmdate('Y-m-d')) {
                return ['error' => 'Birth date must be a valid YYYY-MM-DD date that is not in the future.'];
            }
        }
        $barangayId = filter_var($row['barangayId'], FILTER_VALIDATE_INT);
        if ($user['role_slug'] === 'barangay_personnel') {
            $assignedBarangayId = (int) $user['barangay_id'];
            if ($row['barangayId'] !== '' && ($barangayId === false || $barangayId !== $assignedBarangayId)) {
                return ['error' => 'Barangay ID must match your assigned barangay.'];
            }
            $barangayId = $assignedBarangayId;
        } elseif ($barangayId === false || $barangayId < 1) {
            return ['error' => 'A valid Barangay ID is required.'];
        }
        if (!$this->Data_transfer_model->has_verified_active_barangay($barangayId)) {
            return ['error' => 'Barangay ID must refer to an active, verified CAO barangay.'];
        }

        return [
            'data' => [
                'barangayId' => (int) $barangayId,
                'registryNumber' => $row['registryNumber'] ?: null,
                'firstName' => $row['firstName'],
                'middleName' => $row['middleName'] ?: null,
                'lastName' => $row['lastName'],
                'suffix' => $row['suffix'] ?: null,
                'sex' => $row['sex'] ?: null,
                'birthDate' => $birthDate ?: null,
                'phone' => $row['phone'] ?: null,
                'email' => $email ?: null,
                'address' => $row['address'] ?: null,
                'primaryLivelihood' => $row['primaryLivelihood'] ?: null,
            ],
        ];
    }
}
