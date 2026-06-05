<?php

declare(strict_types=1);

namespace Kivopress;

final class Auth
{
    private ?array $apiUser = null;
    private bool $apiChecked = false;

    public function __construct(private Database $db)
    {
    }

    public function hasUsers(): bool
    {
        $row = $this->db->first('SELECT COUNT(*) AS total FROM users');

        return (int) ($row['total'] ?? 0) > 0;
    }

    public function createAdmin(string $name, string $email, string $password): array
    {
        if ($this->hasUsers()) {
            throw new \RuntimeException('Admin user already exists.');
        }

        $userId = $this->db->insert('users', [
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
            'api_token_hash' => null,
            'created_at' => $this->db->now(),
        ]);

        $this->loginById($userId);

        return ['user' => $this->userById($userId)];
    }

    public function roles(): array
    {
        $labels = [];

        foreach ($this->roleDefinitions() as $role => $definition) {
            $labels[$role] = $definition['label'];
        }

        return apply_filters('auth.roles', $labels);
    }

    public function roleDefinitions(): array
    {
        return apply_filters('auth.role_definitions', [
            'admin' => [
                'label' => 'Administrator',
                'description' => 'Full access to content, media, users, settings, themes, plugins, and API tokens.',
                'capabilities' => ['read', 'edit_posts', 'publish_posts', 'delete_posts', 'edit_pages', 'publish_pages', 'delete_pages', 'upload_media', 'manage_media', 'manage_users', 'manage_settings', 'manage_extensions'],
            ],
            'editor' => [
                'label' => 'Editor',
                'description' => 'Can manage published content, pages, and media, but cannot manage users, settings, themes, or plugins.',
                'capabilities' => ['read', 'edit_posts', 'publish_posts', 'delete_posts', 'edit_pages', 'publish_pages', 'delete_pages', 'upload_media', 'manage_media'],
            ],
            'author' => [
                'label' => 'Author',
                'description' => 'Can create, publish, and manage posts and upload media.',
                'capabilities' => ['read', 'edit_posts', 'publish_posts', 'delete_posts', 'upload_media'],
            ],
            'subscriber' => [
                'label' => 'Subscriber',
                'description' => 'Can sign in and access the dashboard only.',
                'capabilities' => ['read'],
            ],
        ]);
    }

    public function roleCapabilities(string $role): array
    {
        $definitions = $this->roleDefinitions();

        return $definitions[$role]['capabilities'] ?? ['read'];
    }

    public function roleDescription(string $role): string
    {
        $definitions = $this->roleDefinitions();

        return (string) ($definitions[$role]['description'] ?? '');
    }

    public function can(string $capability, ?array $user = null): bool
    {
        $user ??= $this->user();

        if (!$user) {
            return false;
        }

        return in_array($capability, $this->roleCapabilities((string) ($user['role'] ?? 'subscriber')), true);
    }

    public function allUsers(array $query = []): array
    {
        $where = [];
        $params = [];
        $role = (string) ($query['role'] ?? '');
        $search = trim((string) ($query['search'] ?? ''));

        if ($role !== '' && isset($this->roles()[$role])) {
            $where[] = 'role = :role';
            $params['role'] = $role;
        }

        if ($search !== '') {
            $where[] = '(name LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $limit = max(1, min(100, (int) ($query['limit'] ?? 100)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $rows = $this->db->select(
            "SELECT id, name, email, role, created_at FROM users{$clause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return array_map(fn (array $row): array => $this->castUser($row), $rows);
    }

    public function countUsers(?string $role = null): int
    {
        if ($role && isset($this->roles()[$role])) {
            $row = $this->db->first('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => $role]);

            return (int) ($row['total'] ?? 0);
        }

        $row = $this->db->first('SELECT COUNT(*) AS total FROM users');

        return (int) ($row['total'] ?? 0);
    }

    public function createUser(array $data): array
    {
        $values = $this->validateUserData($data, null, true);
        $id = $this->db->insert('users', [
            'name' => $values['name'],
            'email' => $values['email'],
            'password_hash' => password_hash($values['password'], PASSWORD_DEFAULT),
            'role' => $values['role'],
            'api_token_hash' => null,
            'created_at' => $this->db->now(),
        ]);

        return $this->userById($id);
    }

    public function updateUser(int $id, array $data): ?array
    {
        $current = $this->userById($id);

        if (!$current) {
            return null;
        }

        $values = $this->validateUserData($data, $id, false);

        if ($current['role'] === 'admin' && $values['role'] !== 'admin' && $this->countUsers('admin') <= 1) {
            throw new \RuntimeException('At least one administrator is required.');
        }

        $update = [
            'name' => $values['name'],
            'email' => $values['email'],
            'role' => $values['role'],
        ];

        if ($values['password'] !== '') {
            $update['password_hash'] = password_hash($values['password'], PASSWORD_DEFAULT);
        }

        $this->db->update('users', $update, 'id = :id', ['id' => $id]);

        return $this->userById($id);
    }

    public function deleteUser(int $id, int $currentUserId): bool
    {
        $user = $this->userById($id);

        if (!$user) {
            return false;
        }

        if ($id === $currentUserId) {
            throw new \RuntimeException('You cannot delete your own account.');
        }

        if ($user['role'] === 'admin' && $this->countUsers('admin') <= 1) {
            throw new \RuntimeException('At least one administrator is required.');
        }

        $this->db->update('content', ['author_id' => null], 'author_id = :id', ['id' => $id]);
        $this->db->execute('DELETE FROM api_tokens WHERE user_id = :user_id', ['user_id' => $id]);

        return $this->db->execute('DELETE FROM users WHERE id = :id', ['id' => $id]) > 0;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->db->first('SELECT * FROM users WHERE email = :email LIMIT 1', [
            'email' => strtolower(trim($email)),
        ]);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $this->loginById((int) $user['id']);

        return true;
    }

    public function logout(): void
    {
        $this->session();
        unset($_SESSION['kivopress_user_id']);
    }

    public function user(): ?array
    {
        $this->session();
        $id = $_SESSION['kivopress_user_id'] ?? null;

        return $id ? $this->userById((int) $id) : null;
    }

    public function userById(int $id): ?array
    {
        $user = $this->db->first('SELECT id, name, email, role, created_at FROM users WHERE id = :id LIMIT 1', ['id' => $id]);

        if (!$user) {
            return null;
        }

        return $this->castUser($user);
    }

    public function requireAdmin(): ?Response
    {
        if ($this->user()) {
            return null;
        }

        return Response::redirect('/admin/login');
    }

    public function canManageUsers(): bool
    {
        return $this->can('manage_users');
    }

    public function issueApiToken(int $userId, string $name = 'API token', ?int $ttlDays = 90): string
    {
        return $this->createApiToken($userId, $name, $ttlDays)['token'];
    }

    public function createApiToken(int $userId, string $name, ?int $ttlDays = 90): array
    {
        $token = 'kp_' . bin2hex(random_bytes(32));
        $now = $this->db->now();
        $expiresAt = $ttlDays && $ttlDays > 0 ? gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400)) : null;
        $id = $this->db->insert('api_tokens', [
            'user_id' => $userId,
            'name' => trim($name) ?: 'API token',
            'token_lookup' => hash('sha256', $token),
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'abilities' => json_encode(['*'], JSON_UNESCAPED_SLASHES),
            'expires_at' => $expiresAt,
            'last_used_at' => null,
            'revoked_at' => null,
            'rotated_at' => null,
            'created_at' => $now,
        ]);

        return [
            'token' => $token,
            'record' => [
                'id' => $id,
                'user_id' => $userId,
                'name' => trim($name) ?: 'API token',
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ],
        ];
    }

    public function apiTokens(int $userId): array
    {
        $tokens = $this->db->select(
            'SELECT id, user_id, name, expires_at, last_used_at, revoked_at, rotated_at, created_at FROM api_tokens WHERE user_id = :user_id ORDER BY created_at DESC',
            ['user_id' => $userId]
        );

        foreach ($tokens as &$token) {
            $token['id'] = (int) $token['id'];
            $token['user_id'] = (int) $token['user_id'];
            $token['expires_at'] ??= null;
            $token['rotated_at'] ??= null;
            $token['expired'] = $this->tokenExpired($token);
        }

        return $tokens;
    }

    public function revokeApiToken(int $userId, int $tokenId): bool
    {
        return $this->db->update('api_tokens', [
            'revoked_at' => $this->db->now(),
        ], 'id = :id AND user_id = :user_id', [
            'id' => $tokenId,
            'user_id' => $userId,
        ]) > 0;
    }

    public function rotateApiToken(int $userId, int $tokenId, ?int $ttlDays = 90): ?array
    {
        $record = $this->db->first(
            'SELECT id, user_id, name, revoked_at FROM api_tokens WHERE id = :id AND user_id = :user_id LIMIT 1',
            ['id' => $tokenId, 'user_id' => $userId]
        );

        if (!$record || !empty($record['revoked_at'])) {
            return null;
        }

        $now = $this->db->now();
        $this->db->update('api_tokens', [
            'revoked_at' => $now,
            'rotated_at' => $now,
        ], 'id = :id AND user_id = :user_id', [
            'id' => $tokenId,
            'user_id' => $userId,
        ]);

        return $this->createApiToken($userId, (string) $record['name'], $ttlDays);
    }

    public function revokeExpiredApiTokens(): int
    {
        $count = 0;
        $tokens = $this->db->select('SELECT id, expires_at, revoked_at FROM api_tokens WHERE revoked_at IS NULL');

        foreach ($tokens as $token) {
            if (!$this->tokenExpired($token)) {
                continue;
            }

            $count += $this->db->update('api_tokens', [
                'revoked_at' => $this->db->now(),
            ], 'id = :id', ['id' => (int) $token['id']]);
        }

        return $count;
    }

    public function apiUser(): ?array
    {
        if ($this->apiChecked) {
            return $this->apiUser;
        }

        $this->apiChecked = true;
        $token = function_exists('app') ? app()->request()->bearerToken() : null;

        if ($token === null) {
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

            if (preg_match('/Bearer\s+(.+)/i', $header, $match)) {
                $token = trim($match[1]);
            } else {
                $header = $_SERVER['HTTP_X_KIVOPRESS_TOKEN'] ?? '';
                $token = trim($header);
            }
        }

        if ($token === '') {
            return null;
        }

        $lookup = hash('sha256', $token);
        $tokens = $this->db->select(
            'SELECT id, user_id, token_hash, expires_at FROM api_tokens WHERE token_lookup = :lookup AND revoked_at IS NULL LIMIT 1',
            ['lookup' => $lookup]
        );

        if ($tokens === []) {
            $tokens = $this->db->select('SELECT id, user_id, token_hash, expires_at FROM api_tokens WHERE token_lookup IS NULL AND revoked_at IS NULL');
        }

        foreach ($tokens as $record) {
            if ($this->tokenExpired($record)) {
                continue;
            }

            if (password_verify($token, $record['token_hash'])) {
                $this->db->update('api_tokens', [
                    'last_used_at' => $this->db->now(),
                ], 'id = :id', ['id' => (int) $record['id']]);

                return $this->apiUser = $this->userById((int) $record['user_id']);
            }
        }

        return null;
    }

    public function csrfToken(): string
    {
        $this->session();

        if (empty($_SESSION['kivopress_csrf'])) {
            $_SESSION['kivopress_csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['kivopress_csrf'];
    }

    public function validCsrf(?string $token): bool
    {
        $this->session();

        return is_string($token) && hash_equals($_SESSION['kivopress_csrf'] ?? '', $token);
    }

    public function flash(string $key, mixed $value = null): mixed
    {
        $this->session();

        if (func_num_args() === 2) {
            $_SESSION['kivopress_flash'][$key] = $value;

            return null;
        }

        $value = $_SESSION['kivopress_flash'][$key] ?? null;
        unset($_SESSION['kivopress_flash'][$key]);

        return $value;
    }

    private function loginById(int $userId): void
    {
        $this->session();
        $_SESSION['kivopress_user_id'] = $userId;
    }

    private function validateUserData(array $data, ?int $ignoreId, bool $passwordRequired): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = (string) ($data['role'] ?? 'author');
        $password = (string) ($data['password'] ?? '');

        if ($name === '') {
            throw new \RuntimeException('Name is required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid email address is required.');
        }

        if (!isset($this->roles()[$role])) {
            throw new \RuntimeException('Invalid user role.');
        }

        $existing = $this->db->first('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        if ($existing && (int) ($existing['id'] ?? 0) !== (int) $ignoreId) {
            throw new \RuntimeException('A user with this email already exists.');
        }

        if ($passwordRequired && strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters.');
        }

        if (!$passwordRequired && $password !== '' && strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters.');
        }

        return compact('name', 'email', 'role', 'password');
    }

    private function castUser(array $user): array
    {
        $user['id'] = (int) $user['id'];

        return $user;
    }

    private function tokenExpired(array $token): bool
    {
        $expiresAt = (string) ($token['expires_at'] ?? '');

        return $expiresAt !== '' && strtotime($expiresAt . ' UTC') <= time();
    }

    private function session(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
}
