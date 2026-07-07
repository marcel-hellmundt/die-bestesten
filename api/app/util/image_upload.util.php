<?php

class ImageUpload
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public static function store(array $file, string $relativePath, string $expectedMime): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => false, 'code' => 400, 'message' => 'Keine Datei empfangen'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['status' => false, 'code' => 413, 'message' => 'Datei zu groß'];
        }
        if (mime_content_type($file['tmp_name']) !== $expectedMime || getimagesize($file['tmp_name']) === false) {
            return ['status' => false, 'code' => 415, 'message' => 'Ungültiges Bildformat'];
        }

        $target = self::resolvePath($relativePath);
        if ($target === null) {
            return ['status' => false, 'code' => 500, 'message' => 'IMG_STORAGE_PATH nicht konfiguriert'];
        }
        $dirError = self::ensureDir(dirname($target));
        if ($dirError !== null) {
            return ['status' => false, 'code' => 500, 'message' => "Zielverzeichnis konnte nicht erstellt werden: $dirError (dir: " . dirname($target) . ')'];
        }
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $err = error_get_last()['message'] ?? 'unbekannt';
            return ['status' => false, 'code' => 500, 'message' => "Datei konnte nicht gespeichert werden: $err (target: $target)"];
        }

        return ['status' => true];
    }

    public static function copy(string $sourceRelativePath, string $targetRelativePath): array
    {
        $source = self::resolvePath($sourceRelativePath);
        $target = self::resolvePath($targetRelativePath);
        if ($source === null || $target === null) {
            return ['status' => false, 'code' => 500, 'message' => 'IMG_STORAGE_PATH nicht konfiguriert'];
        }
        if (!is_file($source)) {
            return ['status' => false, 'code' => 404, 'message' => 'Quelldatei nicht gefunden'];
        }
        $dirError = self::ensureDir(dirname($target));
        if ($dirError !== null) {
            return ['status' => false, 'code' => 500, 'message' => "Zielverzeichnis konnte nicht erstellt werden: $dirError (dir: " . dirname($target) . ')'];
        }
        if (!copy($source, $target)) {
            $err = error_get_last()['message'] ?? 'unbekannt';
            return ['status' => false, 'code' => 500, 'message' => "Datei konnte nicht kopiert werden: $err"];
        }

        return ['status' => true];
    }

    private static function resolvePath(string $relativePath): ?string
    {
        $basePath = rtrim($_ENV['IMG_STORAGE_PATH'] ?? '', '/');
        if (!$basePath) return null;
        return $basePath . '/' . ltrim($relativePath, '/');
    }

    /** Returns null on success, or an error message on failure. */
    private static function ensureDir(string $dir): ?string
    {
        if (is_dir($dir)) return null;
        error_clear_last();
        if (@mkdir($dir, 0755, true)) return null;
        return error_get_last()['message'] ?? 'unbekannter Fehler';
    }
}
