<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Config;
use Lumera\Core\Logger;

/**
 * Hardened image upload for the logo and the funnel background.
 *
 * SVG is NOT accepted: safe SVG sanitisation is out of scope for this MVP, and
 * the brief requires it to be rejected rather than accepted unsanitised.
 */
final class UploadService
{
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    /** extension => allowed MIME types */
    private const ALLOWED = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
    ];

    /**
     * @param array<string,mixed> $file entry from $_FILES
     * @return array{ok: bool, path?: string, url?: string, error?: string}
     */
    public function store(array $file, string $prefix = 'asset'): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file was uploaded.'];
        }

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The upload failed. Please try again.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'The file must be 2 MB or smaller.'];
        }

        $original  = (string) ($file['name'] ?? '');
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            return ['ok' => false, 'error' => 'Allowed formats: PNG, JPG, JPEG, WEBP.'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            return ['ok' => false, 'error' => 'The file content does not match its extension.'];
        }

        // Must decode as a real raster image.
        $info = @getimagesize($tmp);

        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return ['ok' => false, 'error' => 'The file is not a valid image.'];
        }

        $dir = Config::basePath('public/assets/uploads');

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Upload directory is not writable.'];
        }

        // Generated name — nothing from the client survives into the filesystem.
        $filename = sprintf(
            '%s-%s-%s.%s',
            preg_replace('/[^a-z0-9_-]/', '', strtolower($prefix)) ?: 'asset',
            date('Ymd'),
            bin2hex(random_bytes(8)),
            $extension
        );

        $destination = $dir . '/' . $filename;

        if (!@move_uploaded_file($tmp, $destination)) {
            return ['ok' => false, 'error' => 'The file could not be saved.'];
        }

        @chmod($destination, 0644);

        Logger::info('upload.stored', ['file' => $filename, 'mime' => $mime, 'bytes' => $size]);

        return [
            'ok'   => true,
            'path' => '/assets/uploads/' . $filename,
            'url'  => Config::appUrl() . '/assets/uploads/' . $filename,
        ];
    }

    /**
     * Deletes a previously stored upload. Only paths inside the uploads folder
     * are ever touched.
     */
    public function delete(?string $publicPath): void
    {
        if (!is_string($publicPath) || $publicPath === '') {
            return;
        }

        if (!str_starts_with($publicPath, '/assets/uploads/')) {
            return;
        }

        $basename = basename($publicPath);

        if ($basename === '' || str_contains($basename, '..')) {
            return;
        }

        $full = Config::basePath('public/assets/uploads/' . $basename);
        $real = realpath($full);
        $root = realpath(Config::basePath('public/assets/uploads'));

        if ($real === false || $root === false || !str_starts_with($real, $root)) {
            return;
        }

        @unlink($real);
    }
}
