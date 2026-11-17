<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Operations extends Controller
{
    public function notifications()
    {
        $this->call->model('Operations_model');
        (new Api_response())->success($this->Operations_model->notifications(Auth_context::user()));
    }

    public function mark_notification_read($id)
    {
        $recordId = $this->positive_integer($id);
        if (!$recordId) {
            (new Api_response())->error('invalid_id', 'The notification ID is invalid.', 422);
        }
        $this->call->model('Operations_model');
        if (!$this->Operations_model->mark_notification_read(Auth_context::user(), $recordId)) {
            (new Api_response())->error('not_found', 'The notification was not found.', 404);
        }
        (new Api_response())->success(['id' => $recordId, 'read' => true]);
    }

    public function announcements()
    {
        $this->call->model('Operations_model');
        (new Api_response())->success($this->Operations_model->announcements(Auth_context::user()));
    }

    public function create_announcement()
    {
        $body = $this->json_body();
        $user = Auth_context::user();
        $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : 'published';
        if (!in_array($status, ['draft', 'published'], true)) {
            (new Api_response())->error('invalid_status', 'Announcement status must be draft or published.', 422);
        }
        $data = [
            'title' => $this->text_field($body['title'] ?? null, 200, 'title', true),
            'content' => $this->text_field($body['content'] ?? null, 50000, 'content', true),
            'status' => $status,
            'barangayId' => ($body['barangayId'] ?? '') === '' ? null : $this->positive_integer($body['barangayId']),
        ];
        if (($body['barangayId'] ?? '') !== '' && !$data['barangayId']) {
            (new Api_response())->error('invalid_barangay', 'The announcement barangay is invalid.', 422);
        }
        if ($user['role_slug'] === 'barangay_personnel') {
            $data['barangayId'] = (int) $user['barangay_id'];
        }
        $this->call->model('Operations_model');
        try {
            $id = $this->Operations_model->create_announcement($user, $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_announcement', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function messages()
    {
        $this->call->model('Operations_model');
        (new Api_response())->success($this->Operations_model->messages(Auth_context::user()));
    }

    public function create_message()
    {
        $body = $this->json_body();
        $conversation = isset($body['conversationKey']) && is_string($body['conversationKey'])
            ? trim($body['conversationKey'])
            : 'general';
        if ($conversation !== 'general') {
            (new Api_response())->error('invalid_conversation', 'Only the general workspace conversation is configured.', 422);
        }
        $data = [
            'conversationKey' => $conversation,
            'body' => $this->text_field($body['body'] ?? null, 10000, 'body', true),
            'barangayId' => ($body['barangayId'] ?? '') === '' ? null : $this->positive_integer($body['barangayId']),
        ];
        if (($body['barangayId'] ?? '') !== '' && !$data['barangayId']) {
            (new Api_response())->error('invalid_barangay', 'The barangay conversation is invalid.', 422);
        }
        $this->call->model('Operations_model');
        try {
            $id = $this->Operations_model->create_message(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_message', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function calendar()
    {
        $this->call->model('Operations_model');
        (new Api_response())->success($this->Operations_model->calendar_events(Auth_context::user()));
    }

    public function create_calendar_event()
    {
        $body = $this->json_body();
        $data = [
            'title' => $this->text_field($body['title'] ?? null, 200, 'title', true),
            'description' => $this->text_field($body['description'] ?? null, 10000, 'description', false),
            'eventType' => $this->text_field($body['eventType'] ?? null, 80, 'eventType', true),
            'startsAt' => $this->datetime_field($body['startsAt'] ?? null, 'startsAt', true),
            'endsAt' => $this->datetime_field($body['endsAt'] ?? null, 'endsAt', false),
            'barangayId' => ($body['barangayId'] ?? '') === '' ? null : $this->positive_integer($body['barangayId']),
        ];
        if (($body['barangayId'] ?? '') !== '' && !$data['barangayId']) {
            (new Api_response())->error('invalid_barangay', 'The barangay audience is invalid.', 422);
        }
        if ($data['endsAt'] !== null && $data['endsAt'] < $data['startsAt']) {
            (new Api_response())->error('invalid_event_range', 'Event end must be after its start.', 422);
        }
        $this->call->model('Operations_model');
        try {
            $id = $this->Operations_model->create_calendar_event(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_event', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function audit_logs()
    {
        $this->call->model('Operations_model');
        (new Api_response())->success($this->Operations_model->audit_logs());
    }

    private function json_body()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide a JSON object.', 400);
        }
        return $body;
    }

    private function positive_integer($value)
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number !== false && $number > 0 ? $number : null;
    }

    private function text_field($value, $maximum, $field, $required)
    {
        if ($value === null || $value === '') {
            if ($required) {
                (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is required.', 422);
            }
            return null;
        }
        if (!is_string($value) || strlen(trim($value)) > $maximum) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is too long or invalid.', 422);
        }
        $value = trim($value);
        if ($required && $value === '') {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is required.', 422);
        }
        return $value === '' ? null : $value;
    }

    private function datetime_field($value, $field, $required)
    {
        if (($value === null || $value === '') && !$required) {
            return null;
        }
        if (!is_string($value)) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a local date and time.', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must use YYYY-MM-DDTHH:MM format.', 422);
        }
        return $date->format('Y-m-d H:i:s');
    }
}
