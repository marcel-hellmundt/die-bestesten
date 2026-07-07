<?php

// api/'s webspace and img.die-bestesten.de's webspace are separate, read-only-to-each-other
// mounts on the same hosting account — direct filesystem writes across that boundary are not
// possible, so uploads go over FTPS instead (same account the deploy workflows already use).
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

        $conn = self::connect();
        if (is_array($conn)) return $conn;

        $result = self::putFile($conn, $file['tmp_name'], $relativePath);
        ftp_close($conn);
        return $result;
    }

    public static function copy(string $sourceRelativePath, string $targetRelativePath): array
    {
        $conn = self::connect();
        if (is_array($conn)) return $conn;

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        if (!@ftp_get($conn, $tmp, self::remotePath($sourceRelativePath), FTP_BINARY)) {
            ftp_close($conn);
            @unlink($tmp);
            return ['status' => false, 'code' => 404, 'message' => 'Quelldatei nicht gefunden'];
        }

        $result = self::putFile($conn, $tmp, $targetRelativePath);
        ftp_close($conn);
        @unlink($tmp);
        return $result;
    }

    private static function connect()
    {
        $host = $_ENV['IMG_FTP_HOST'] ?? null;
        $user = $_ENV['IMG_FTP_USER'] ?? null;
        $pass = $_ENV['IMG_FTP_PASSWORD'] ?? null;
        if (!$host || !$user || !$pass || !($_ENV['IMG_FTP_DIR'] ?? null)) {
            return ['status' => false, 'code' => 500, 'message' => 'IMG_FTP_* nicht konfiguriert'];
        }

        $conn = @ftp_ssl_connect($host);
        if (!$conn) {
            return ['status' => false, 'code' => 500, 'message' => "FTPS-Verbindung zu $host fehlgeschlagen"];
        }
        if (!@ftp_login($conn, $user, $pass)) {
            ftp_close($conn);
            return ['status' => false, 'code' => 500, 'message' => 'FTP-Login fehlgeschlagen'];
        }
        ftp_pasv($conn, true);
        return $conn;
    }

    private static function putFile($conn, string $localFile, string $relativePath): array
    {
        self::ensureRemoteDir($conn, dirname($relativePath));
        if (!@ftp_put($conn, self::remotePath($relativePath), $localFile, FTP_BINARY)) {
            return ['status' => false, 'code' => 500, 'message' => 'Datei konnte nicht per FTP hochgeladen werden'];
        }
        return ['status' => true];
    }

    private static function remotePath(string $relativePath): string
    {
        $base = rtrim($_ENV['IMG_FTP_DIR'] ?? '', '/');
        return $base . '/' . ltrim($relativePath, '/');
    }

    private static function ensureRemoteDir($conn, string $relativeDir): void
    {
        $base = rtrim($_ENV['IMG_FTP_DIR'] ?? '', '/');
        $path = $base;
        foreach (array_filter(explode('/', $relativeDir), fn($p) => $p !== '' && $p !== '.') as $part) {
            $path .= '/' . $part;
            if (!@ftp_chdir($conn, $path)) {
                @ftp_mkdir($conn, $path);
            }
        }
    }
}
