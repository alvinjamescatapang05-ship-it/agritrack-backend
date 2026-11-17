<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function find_by_email($email)
    {
        return $this->db->raw(
            'SELECT users.id, users.username, users.email, users.password,
                    users.role_id, users.barangay_id, users.full_name, users.is_active,
                    roles.slug AS role_slug, roles.name AS role_name,
                    farmers.id AS farmer_id
             FROM users
             LEFT JOIN roles ON roles.id = users.role_id
             LEFT JOIN farmers ON farmers.user_id = users.id
                AND farmers.deleted_at IS NULL
                AND farmers.is_active = 1
             WHERE users.email = ? AND users.deleted_at IS NULL
             LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function find_user_by_token_hash($tokenHash)
    {
        $user = $this->db->raw(
            'SELECT users.id, users.username, users.email, users.full_name,
                    users.role_id, users.barangay_id, roles.slug AS role_slug,
                    roles.name AS role_name, barangays.name AS barangay_name,
                    farmers.id AS farmer_id
             FROM user_sessions
             INNER JOIN users ON users.id = user_sessions.user_id
             INNER JOIN roles ON roles.id = users.role_id
             LEFT JOIN barangays ON barangays.id = users.barangay_id
             LEFT JOIN farmers ON farmers.user_id = users.id
                AND farmers.deleted_at IS NULL
                AND farmers.is_active = 1
             WHERE user_sessions.token_hash = ?
               AND user_sessions.revoked_at IS NULL
               AND user_sessions.expires_at > UTC_TIMESTAMP()
               AND users.is_active = 1
               AND users.deleted_at IS NULL
             LIMIT 1',
            [$tokenHash]
        )->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $this->db->raw(
                'UPDATE user_sessions SET last_used_at = UTC_TIMESTAMP()
                 WHERE token_hash = ? AND revoked_at IS NULL',
                [$tokenHash]
            );
        }

        return $user;
    }

    public function issue_session($userId, $tokenHash, $expiresAt, $ipAddress, $userAgent)
    {
        $this->db->raw(
            'INSERT INTO user_sessions
                (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $tokenHash, $expiresAt, $ipAddress, $userAgent]
        );
    }

    public function revoke_session($tokenHash)
    {
        $this->db->raw(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
             WHERE token_hash = ? AND revoked_at IS NULL',
            [$tokenHash]
        );
    }

    public function update_profile(array $user, array $changes)
    {
        $current = $this->db->raw(
            'SELECT id, username, email, full_name, password
             FROM users
             WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1',
            [(int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return false;
        }

        $sensitiveChange = (
            array_key_exists('email', $changes) && $changes['email'] !== $current['email']
        ) || array_key_exists('password', $changes);
        if ($sensitiveChange && (
            !isset($changes['currentPassword']) ||
            !password_verify($changes['currentPassword'], $current['password'])
        )) {
            throw new InvalidArgumentException('Your current password is incorrect.');
        }

        $sets = [];
        $params = [];
        $nextName = array_key_exists('fullName', $changes) ? $changes['fullName'] : $current['full_name'];
        $nextEmail = array_key_exists('email', $changes) ? $changes['email'] : $current['email'];

        if ($nextName !== $current['full_name']) {
            $sets[] = 'full_name = ?';
            $params[] = $nextName;
        }
        if ($nextEmail !== $current['email']) {
            $sets[] = 'email = ?';
            $params[] = $nextEmail;
        }
        if (array_key_exists('password', $changes)) {
            if (password_verify($changes['password'], $current['password'])) {
                throw new InvalidArgumentException('Choose a password different from your current password.');
            }
            $hash = password_hash($changes['password'], PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Password hashing failed.');
            }
            $sets[] = 'password = ?';
            $params[] = $hash;
        }
        if (!$sets) {
            return [
                'id' => (int) $current['id'],
                'username' => $current['username'],
                'email' => $current['email'],
                'name' => $current['full_name'] ?: $current['username'],
                'passwordChanged' => false,
            ];
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
                array_merge($params, [(int) $user['id']])
            );
            if (array_key_exists('password', $changes)) {
                $this->db->raw(
                    'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND revoked_at IS NULL',
                    [(int) $user['id']]
                );
            }

            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
                array_key_exists('password', $changes) ? 'credentials_changed' : 'profile_updated',
                'user_profile',
                (int) $user['id'],
                ['email' => $current['email'], 'full_name' => $current['full_name']],
                ['email' => $nextEmail, 'full_name' => $nextName, 'password_changed' => array_key_exists('password', $changes)]
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }

        return [
            'id' => (int) $current['id'],
            'username' => $current['username'],
            'email' => $nextEmail,
            'name' => $nextName ?: $current['username'],
            'passwordChanged' => array_key_exists('password', $changes),
        ];
    }

    public function failed_attempts_in_window($identityHash, $ipAddress)
    {
        return (int) $this->db->raw(
            'SELECT COUNT(*) FROM auth_login_attempts
             WHERE was_successful = 0
               AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE
               AND (identity_hash = ? OR ip_address = ?)',
            [$identityHash, $ipAddress]
        )->fetchColumn();
    }

    public function record_login_attempt($identityHash, $ipAddress, $successful)
    {
        $this->db->raw(
            'DELETE FROM auth_login_attempts
             WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        $this->db->raw(
            'INSERT INTO auth_login_attempts (identity_hash, ip_address, was_successful, attempted_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$identityHash, $ipAddress, $successful ? 1 : 0]
        );
    }
}
