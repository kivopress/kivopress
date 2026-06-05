<?php

declare(strict_types=1);

namespace Kivopress;

use ZipArchive;

final class PackageInstaller
{
    private const MAX_BYTES = 26214400;

    public function __construct(private string $rootPath)
    {
    }

    public function installThemeUpload(array $file): array
    {
        return $this->installUpload($file, 'theme');
    }

    public function installPluginUpload(array $file): array
    {
        return $this->installUpload($file, 'plugin');
    }

    public function installFromPath(string $path, string $originalName, string $kind): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to upload packages.');
        }

        if (!is_file($path)) {
            throw new \InvalidArgumentException('Uploaded package could not be read.');
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Package is too large. Maximum size is 25 MB.');
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \InvalidArgumentException('Only .zip packages are supported.');
        }

        $kind = $kind === 'theme' ? 'theme' : 'plugin';
        $required = $kind === 'theme' ? 'index.php' : 'plugin.php';
        $baseDir = $this->rootPath . '/kp-content/' . ($kind === 'theme' ? 'themes' : 'plugins');
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Package is not a valid zip archive.');
        }

        $temp = null;

        try {
            $entries = $this->entries($zip);
            [$prefix, $slug] = $this->packageRoot($entries, $required, $originalName);
            $destination = $baseDir . '/' . $slug;

            if (is_dir($destination)) {
                throw new \InvalidArgumentException(ucfirst($kind) . ' already exists: ' . $slug);
            }

            $temp = $this->rootPath . '/kp-content/uploads/' . $kind . '-' . bin2hex(random_bytes(6));
            $this->extract($zip, $entries, $prefix, $temp);

            if (!is_file($temp . '/' . $required)) {
                $this->removeDirectory($temp);
                throw new \InvalidArgumentException(ucfirst($kind) . ' package must contain ' . $required . '.');
            }

            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0775, true);
            }

            if (!rename($temp, $destination)) {
                throw new \RuntimeException('Could not move package into content directory.');
            }

            return ['slug' => $slug, 'path' => $destination];
        } catch (\Throwable $exception) {
            if ($temp && is_dir($temp)) {
                $this->removeDirectory($temp);
            }

            throw $exception;
        } finally {
            $zip->close();
        }
    }

    private function installUpload(array $file, string $kind): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Upload was not accepted by PHP.');
        }

        return $this->installFromPath($tmp, (string) ($file['name'] ?? 'package.zip'), $kind);
    }

    private function entries(ZipArchive $zip): array
    {
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

            if (str_starts_with($name, './')) {
                $name = substr($name, 2);
            }

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            if (!$this->safePath($name)) {
                throw new \InvalidArgumentException('Package contains an unsafe path: ' . $name);
            }

            if (str_starts_with($name, '__MACOSX/') || str_ends_with($name, '/.DS_Store')) {
                continue;
            }

            if (!$this->allowedFile($name)) {
                throw new \InvalidArgumentException('Package contains a blocked file type: ' . $name);
            }

            $entries[] = $name;
        }

        if ($entries === []) {
            throw new \InvalidArgumentException('Package is empty.');
        }

        return $entries;
    }

    private function packageRoot(array $entries, string $required, string $originalName): array
    {
        if (in_array($required, $entries, true)) {
            return ['', $this->slug(pathinfo($originalName, PATHINFO_FILENAME))];
        }

        $candidates = [];

        foreach ($entries as $entry) {
            $parts = explode('/', $entry, 2);

            if (count($parts) === 2 && $parts[1] === $required) {
                $candidates[] = $parts[0];
            }
        }

        $candidates = array_values(array_unique($candidates));

        if (count($candidates) !== 1) {
            throw new \InvalidArgumentException('Package must contain a single folder with ' . $required . '.');
        }

        return [$candidates[0] . '/', $this->slug($candidates[0])];
    }

    private function extract(ZipArchive $zip, array $entries, string $prefix, string $destination): void
    {
        foreach ($entries as $entry) {
            if ($prefix !== '' && !str_starts_with($entry, $prefix)) {
                continue;
            }

            $relative = $prefix === '' ? $entry : substr($entry, strlen($prefix));

            if ($relative === '' || !$this->safePath($relative)) {
                continue;
            }

            $target = $destination . '/' . $relative;
            $dir = dirname($target);

            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $contents = $zip->getFromName($entry);

            if ($contents === false) {
                $contents = $zip->getFromName(str_replace('/', '\\', $entry));
            }

            if ($contents === false) {
                throw new \RuntimeException('Could not read package entry: ' . $entry);
            }

            file_put_contents($target, $contents);
        }
    }

    private function safePath(string $path): bool
    {
        return !str_contains($path, "\0")
            && !str_starts_with($path, '/')
            && !preg_match('/^[a-zA-Z]:/', $path)
            && !str_contains('/' . $path . '/', '/../')
            && !str_contains('/' . $path . '/', '/./');
    }

    private function allowedFile(string $path): bool
    {
        $blocked = ['exe', 'bat', 'cmd', 'com', 'scr', 'ps1', 'phar', 'phtml'];
        $basename = strtolower(basename($path));
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return !in_array($ext, $blocked, true)
            && !in_array($basename, ['.htaccess', '.user.ini', 'php.ini'], true);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_-]+/i', '-', $slug) ?: '';
        $slug = trim($slug, '-_');

        if ($slug === '') {
            throw new \InvalidArgumentException('Package folder name is invalid.');
        }

        return $slug;
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded package is too large.',
            UPLOAD_ERR_PARTIAL => 'Package upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'Choose a package to upload.',
            default => 'Package upload failed.',
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $target = $path . '/' . $item;
            is_dir($target) ? $this->removeDirectory($target) : unlink($target);
        }

        rmdir($path);
    }
}
