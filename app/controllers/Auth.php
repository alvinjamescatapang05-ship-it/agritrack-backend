<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Api_response.php';
require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Audit_logger.php';

class Auth extends Controller
{
    public function login()
    {
        $this->require_database_configuration();

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 16384) {
            (new Api_response())->error('request_too_large', 'The request body is too large.', 413);
        }

        $body = $this->request->json();
        $email = is_array($body) && isset($body['email']) && is_string($body['email'])
            ? strtolower(trim($body['email']))
            : '';
        $password = is_array($body) && isset($body['password']) && is_string($body['password'])
            ? $body['password']
            : '';

        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : 'unknown';
        $identityHash = hash('sha256', $email);

        $this->call->model('Auth_model');
        if ($this->Auth_model->failed_attempts_in_window($identityHash, $ipAddress) >= 10) {
            (new Api_response())->error(
                'too_many_attempts',
                'Too many sign-in attempts. Please wait 15 minutes before trying again.',
                429
            );
        }

        $user = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255 && strlen($password) <= 1024
            ? $this->Auth_model->find_by_email($email)
            : false;

        if (!$user || empty($user['is_active']) || !password_verify($password, $user['password'])) {
            $this->Auth_model->record_login_attempt($identityHash, $ipAddress, false);
            (new Audit_logger())->record(
                null,
                null,
                null,
                'login_failed',
                'authentication',
                null,
                null,
                ['identity_hash' => $identityHash]
            );
            (new Api_response())->error('invalid_credentials', 'Email or password is incorrect.', 401);
        }

        if (empty($user['role_id']) || empty($user['role_slug'])) {
            (new Api_response())->error(
                'role_assignment_required',
                'This account does not have a configured system role.',
                403
            );
        }

        if ($user['role_slug'] === 'barangay_personnel' && empty($user['barangay_id'])) {
            (new Api_response())->error(
                'barangay_assignment_required',
                'This account must be assigned to a barangay before it can sign in.',
                403
            );
        }

        if ($user['role_slug'] === 'farmer' && empty($user['farmer_id'])) {
            (new Api_response())->error(
                'farmer_profile_required',
                'This account must be linked to an active farmer profile before it can sign in.',
                403
            );
        }

        $this->call->model('Auth_model');
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + 43200;
        $tokenHash = hash('sha256', $token);
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

        $this->Auth_model->issue_session(
            (int) $user['id'],
            $tokenHash,
            gmdate('Y-m-d H:i:s', $expiresAt),
            $ipAddress,
            $userAgent
        );
        $this->Auth_model->record_login_attempt($identityHash, $ipAddress, true);

        $audit = new Audit_logger();
        $audit->record(
            (int) $user['id'],
            $user['role_slug'],
            isset($user['barangay_id']) ? (int) $user['barangay_id'] : null,
            'login',
            'authentication'
        );

        (new Api_response())->success([
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => gmdate(DATE_ATOM, $expiresAt),
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'name' => $user['full_name'] ?: $user['username'],
                'role' => $user['role_slug'],
                'barangayId' => $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
                'farmerId' => $user['farmer_id'] === null ? null : (int) $user['farmer_id'],
            ],
        ]);
    }

    public function me()
    {
        $user = Auth_context::user();
        (new Api_response())->success([
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'name' => $user['full_name'] ?: $user['username'],
            'role' => $user['role_slug'],
            'roleName' => $user['role_name'],
            'barangayId' => $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
            'barangayName' => $user['barangay_name'],
            'farmerId' => $user['farmer_id'] === null ? null : (int) $user['farmer_id'],
        ]);
    }

    public function update_profile()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide profile details as a JSON object.', 400);
        }

        $changes = [];
        if (array_key_exists('fullName', $body)) {
            if (!is_string($body['fullName'])) {
                (new Api_response())->error('invalid_name', 'Name must be text.', 422);
            }
            $fullName = trim($body['fullName']);
            if ($fullName === '' || strlen($fullName) > 150) {
                (new Api_response())->error('invalid_name', 'Name must contain 1–150 characters.', 422);
            }
            $changes['fullName'] = $fullName;
        }
        if (array_key_exists('email', $body)) {
            if (!is_string($body['email'])) {
                (new Api_response())->error('invalid_email', 'Email must be text.', 422);
            }
            $email = strtolower(trim($body['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                (new Api_response())->error('invalid_email', 'Provide a valid email address.', 422);
            }
            $changes['email'] = $email;
        }
        if (array_key_exists('password', $body)) {
            if (!is_string($body['password']) || strlen($body['password']) < 12 || strlen($body['password']) > 1024) {
                (new Api_response())->error('weak_password', 'Password must contain 12–1024 characters.', 422);
            }
            $changes['password'] = $body['password'];
        }
        if (array_key_exists('currentPassword', $body)) {
            if (!is_string($body['currentPassword']) || strlen($body['currentPassword']) > 1024) {
                (new Api_response())->error('invalid_current_password', 'Current password is invalid.', 422);
            }
            $changes['currentPassword'] = $body['currentPassword'];
        }
        if (!$changes || (count($changes) === 1 && array_key_exists('currentPassword', $changes))) {
            (new Api_response())->error('invalid_request', 'Provide a name, email, or password change.', 422);
        }

        $this->call->model('Auth_model');
        try {
            $result = $this->Auth_model->update_profile(Auth_context::user(), $changes);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_profile_update', $exception->getMessage(), 422);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                (new Api_response())->error('email_in_use', 'That email address is already associated with another account.', 409);
            }
            throw $exception;
        }
        if (!$result) {
            (new Api_response())->error('not_found', 'The account is no longer active.', 404);
        }

        (new Api_response())->success($result);
    }

    public function logout()
    {
        $authorization = isset($_SERVER['HTTP_AUTHORIZATION'])
            ? trim($_SERVER['HTTP_AUTHORIZATION'])
            : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) : '');

        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $authorization, $matches)) {
            (new Api_response())->error('unauthenticated', 'Authentication is required.', 401);
        }

        $user = Auth_context::user();
        $this->call->model('Auth_model');
        $this->Auth_model->revoke_session(hash('sha256', strtolower($matches[1])));

        $audit = new Audit_logger();
        $audit->record(
            (int) $user['id'],
            $user['role_slug'],
            $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
            'logout',
            'authentication'
        );

        (new Api_response())->success(['message' => 'Signed out successfully.']);
    }

    public function options()
    {
        http_response_code(204);
        exit;
    }

    private function require_database_configuration()
    {
        if (
            (getenv('DB_HOST') ?: '') === '' ||
            (getenv('DB_USERNAME') ?: '') === '' ||
            (getenv('DB_NAME') ?: '') === ''
        ) {
            (new Api_response())->error(
                'database_not_configured',
                'Configure DB_HOST, DB_USERNAME, and DB_NAME in the backend .env before signing in.',
                503
            );
        }
    }
}
