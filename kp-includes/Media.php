<?php

declare(strict_types=1);

namespace Kivopress;

final class Media
{
    private const MAX_UPLOAD_BYTES = 26214400;
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'js', 'html', 'htm', 'svg', 'exe', 'bat', 'cmd', 'com', 'scr', 'ps1'];

    public function __construct(private Database $db, private string $rootPath)
    {
    }

    public function uploadMany(array $files, ?int $userId = null): array
    {
        $uploads = $this->normalizeFiles($files);
        $created = [];
        $errors = [];

        foreach ($uploads as $file) {
            try {
                $created[] = $this->upload($file, $userId);
            } catch (\Throwable $exception) {
                $errors[] = (($file['name'] ?? 'Upload') ?: 'Upload') . ': ' . $exception->getMessage();
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    public function upload(array $file, ?int $userId = null): array
    {
        $this->validateUpload($file);

        $originalName = $this->cleanOriginalName((string) $file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = $this->detectMime((string) $file['tmp_name']);
        $this->validateType($extension, $mime);

        $now = $this->db->now();
        $date = gmdate('Y/m');
        $dir = $this->rootPath . '/kp-content/uploads/' . $date;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $base = $this->slug(pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $this->uniqueFilename($dir, $base . '-' . substr(bin2hex(random_bytes(6)), 0, 10), $extension);
        $target = $dir . '/' . $filename;

        if (!is_uploaded_file((string) $file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Invalid uploaded file.');
        }

        $moved = PHP_SAPI === 'cli'
            ? rename((string) $file['tmp_name'], $target)
            : move_uploaded_file((string) $file['tmp_name'], $target);

        if (!$moved) {
            throw new \RuntimeException('Could not store uploaded file.');
        }

        $dimensions = $this->dimensions($target, $mime);
        $id = $this->db->insert('media', [
            'filename' => $filename,
            'original_name' => $originalName,
            'disk_path' => 'kp-content/uploads/' . $date . '/' . $filename,
            'mime' => $mime,
            'extension' => $extension,
            'size' => (int) filesize($target),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'title' => trim(pathinfo($originalName, PATHINFO_FILENAME)) ?: $originalName,
            'alt' => '',
            'caption' => '',
            'uploaded_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        do_action('media.uploaded', $id);

        return $this->find($id) ?? [];
    }

    public function all(array $query = []): array
    {
        $limit = max(1, min(200, (int) ($query['limit'] ?? 60)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $type = (string) ($query['type'] ?? '');
        $search = trim((string) ($query['search'] ?? ''));
        $params = [];
        $where = [];

        if (in_array($type, ['image', 'audio', 'video'], true)) {
            $where[] = 'mime LIKE :mime';
            $params['mime'] = $type . '/%';
        } elseif ($type === 'document') {
            $where[] = "mime NOT LIKE 'image/%' AND mime NOT LIKE 'audio/%' AND mime NOT LIKE 'video/%'";
        }

        if ($search !== '') {
            $where[] = '(title LIKE :search OR original_name LIKE :search OR alt LIKE :search OR caption LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $rows = $this->db->select("SELECT * FROM media{$clause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    public function count(array $query = []): int
    {
        $type = (string) ($query['type'] ?? '');
        $search = trim((string) ($query['search'] ?? ''));
        $params = [];
        $where = [];

        if (in_array($type, ['image', 'audio', 'video'], true)) {
            $where[] = 'mime LIKE :mime';
            $params['mime'] = $type . '/%';
        } elseif ($type === 'document') {
            $where[] = "mime NOT LIKE 'image/%' AND mime NOT LIKE 'audio/%' AND mime NOT LIKE 'video/%'";
        }

        if ($search !== '') {
            $where[] = '(title LIKE :search OR original_name LIKE :search OR alt LIKE :search OR caption LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $row = $this->db->first("SELECT COUNT(*) AS total FROM media{$clause}", $params);

        return (int) ($row['total'] ?? 0);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM media WHERE id = :id LIMIT 1', ['id' => $id]);

        return $row ? $this->hydrate($row) : null;
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);

        if (!$current) {
            return null;
        }

        $this->db->update('media', [
            'title' => trim((string) ($data['title'] ?? $current['title'])) ?: $current['title'],
            'alt' => array_key_exists('alt', $data) ? trim((string) $data['alt']) : $current['alt'],
            'caption' => array_key_exists('caption', $data) ? trim((string) $data['caption']) : $current['caption'],
            'updated_at' => $this->db->now(),
        ], 'id = :id', ['id' => $id]);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $item = $this->find($id);

        if (!$item) {
            return false;
        }

        $path = $this->pathFor($item);

        if (is_file($path)) {
            unlink($path);
        }

        $this->db->execute('DELETE FROM media WHERE id = :id', ['id' => $id]);
        do_action('media.deleted', $item);

        return true;
    }

    public function serve(int $id, string $filename): Response
    {
        $item = $this->find($id);

        if (!$item || $item['filename'] !== $filename) {
            return Response::html('Media not found.', 404);
        }

        $path = $this->pathFor($item);

        if (!is_file($path)) {
            return Response::html('Media file is missing.', 404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $item['mime'],
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Disposition' => 'inline; filename="' . addslashes($item['original_name']) . '"',
        ]);
    }

    private function validateUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        if (empty($file['tmp_name']) || !is_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('Missing uploaded file.');
        }

        $size = (int) ($file['size'] ?? 0);
        $max = (int) apply_filters('media.max_upload_size', self::MAX_UPLOAD_BYTES);

        if ($size <= 0) {
            throw new \RuntimeException('The file is empty.');
        }

        if ($size > $max) {
            throw new \RuntimeException('The file is too large. Maximum size is ' . $this->humanSize($max) . '.');
        }
    }

    private function validateType(string $extension, string $mime): void
    {
        if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new \RuntimeException('This file type is not allowed.');
        }

        $allowed = apply_filters('media.allowed_types', [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'avif' => ['image/avif'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'mp3' => ['audio/mpeg'],
            'wav' => ['audio/wav', 'audio/x-wav'],
            'ogg' => ['audio/ogg', 'video/ogg'],
            'm4a' => ['audio/mp4', 'audio/x-m4a'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'mov' => ['video/quicktime'],
        ]);

        if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
            throw new \RuntimeException('Unsupported media type: ' . $extension . ' / ' . $mime . '.');
        }
    }

    private function normalizeFiles(array $files): array
    {
        if (!is_array($files['name'] ?? null)) {
            return $files ? [$files] : [];
        }

        $normalized = [];

        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['size'] = (int) ($row['size'] ?? 0);
        $row['width'] = $row['width'] !== null && $row['width'] !== '' ? (int) $row['width'] : null;
        $row['height'] = $row['height'] !== null && $row['height'] !== '' ? (int) $row['height'] : null;
        $row['uploaded_by'] = $row['uploaded_by'] !== null && $row['uploaded_by'] !== '' ? (int) $row['uploaded_by'] : null;
        $row['url'] = '/media/' . $row['id'] . '/' . rawurlencode($row['filename']);
        $row['kind'] = $this->kind($row['mime']);
        $row['is_image'] = $row['kind'] === 'image';

        return $row;
    }

    private function pathFor(array $item): string
    {
        $path = ltrim((string) $item['disk_path'], '/');

        if (str_starts_with($path, 'storage/uploads/')) {
            $path = 'kp-content/uploads/' . substr($path, strlen('storage/uploads/'));
        }

        return $this->rootPath . '/' . $path;
    }

    private function detectMime(string $path): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return mime_content_type($path) ?: 'application/octet-stream';
    }

    private function dimensions(string $path, string $mime): array
    {
        if (!str_starts_with($mime, 'image/')) {
            return ['width' => null, 'height' => null];
        }

        $size = @getimagesize($path);

        return [
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
        ];
    }

    private function cleanOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\- ]+/u', '', $name) ?: 'upload';

        return trim($name) ?: 'upload';
    }

    private function uniqueFilename(string $dir, string $base, string $extension): string
    {
        $candidate = $base . '.' . $extension;
        $index = 2;

        while (is_file($dir . '/' . $candidate)) {
            $candidate = $base . '-' . $index++ . '.' . $extension;
        }

        return $candidate;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: 'media';

        return trim($slug, '-') ?: 'media';
    }

    private function kind(string $mime): string
    {
        foreach (['image', 'audio', 'video'] as $kind) {
            if (str_starts_with($mime, $kind . '/')) {
                return $kind;
            }
        }

        return 'document';
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'The upload failed.',
        };
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
