<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Config;
use Lumera\Core\Logger;
use Lumera\Support\SvgSanitizer;

/**
 * Hardened image upload for logos, favicons and funnel backgrounds.
 *
 * SVG is accepted only because every uploaded SVG is rewritten through
 * {@see SvgSanitizer} before it is stored: the file that lands on disk is a
 * rebuilt document containing nothing but allow-listed shapes and attributes.
 * The uploads directory additionally refuses to execute anything and serves a
 * locked-down Content-Security-Policy.
 */
final class UploadService
{
    private const DEFAULT_MAX_MB = 2;
    private const HARD_MAX_MB    = 10;

    /** extension => allowed MIME types */
    private const ALLOWED = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/xml', 'text/plain', 'application/xml', 'application/svg+xml'],
        'ico'  => ['image/vnd.microsoft.icon', 'image/x-icon', 'image/ico'],
    ];

    /** Purpose => extensions accepted for it. */
    private const PURPOSE_EXTENSIONS = [
        'logo'       => ['png', 'svg', 'webp', 'jpg', 'jpeg'],
        'favicon'    => ['png', 'svg', 'webp', 'ico'],
        'background' => ['png', 'webp', 'jpg', 'jpeg'],
    ];

    /** Configurable ceiling, clamped so a misconfiguration cannot open it wide. */
    public function maxBytes(): int
    {
        $configured = Config::int('UPLOAD_MAX_SIZE_MB', self::DEFAULT_MAX_MB);
        $megabytes  = max(1, min(self::HARD_MAX_MB, $configured));

        return $megabytes * 1024 * 1024;
    }

    /** @return list<string> extensions accepted for a purpose */
    public function allowedExtensions(string $purpose): array
    {
        return self::PURPOSE_EXTENSIONS[$purpose] ?? self::PURPOSE_EXTENSIONS['logo'];
    }

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

        $size    = (int) ($file['size'] ?? 0);
        $maxBytes = $this->maxBytes();

        if ($size <= 0 || $size > $maxBytes) {
            return [
                'ok'    => false,
                'error' => sprintf('The file must be %d MB or smaller.', (int) ($maxBytes / 1024 / 1024)),
            ];
        }

        $purpose   = self::PURPOSE_EXTENSIONS[$prefix] ?? null;
        $original  = (string) ($file['name'] ?? '');
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            return ['ok' => false, 'error' => 'Unsupported file format.'];
        }

        if ($purpose !== null && !in_array($extension, $purpose, true)) {
            return [
                'ok'    => false,
                'error' => 'Allowed formats: ' . strtoupper(implode(', ', $purpose)) . '.',
            ];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            return ['ok' => false, 'error' => 'The file content does not match its extension.'];
        }

        $sanitizedSvg = null;

        if ($extension === 'svg') {
            $raw = @file_get_contents($tmp);

            if ($raw === false) {
                return ['ok' => false, 'error' => 'The file could not be read.'];
            }

            $result = (new SvgSanitizer())->sanitize($raw);

            if (!$result['ok']) {
                return ['ok' => false, 'error' => $result['error'] ?? 'The SVG could not be sanitised.'];
            }

            $sanitizedSvg = $result['svg'];
        } elseif ($extension !== 'ico') {
            // Raster formats must actually decode as an image.
            $info = @getimagesize($tmp);

            if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
                return ['ok' => false, 'error' => 'The file is not a valid image.'];
            }
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

        if ($sanitizedSvg !== null) {
            // The sanitised rewrite is what gets stored — never the original.
            if (@file_put_contents($destination, $sanitizedSvg) === false) {
                return ['ok' => false, 'error' => 'The file could not be saved.'];
            }

            @unlink($tmp);
        } elseif (!@move_uploaded_file($tmp, $destination)) {
            return ['ok' => false, 'error' => 'The file could not be saved.'];
        }

        @chmod($destination, 0644);

        Logger::info('upload.stored', [
            'file'      => $filename,
            'mime'      => $mime,
            'bytes'     => $size,
            'sanitized' => $sanitizedSvg !== null,
        ]);

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
