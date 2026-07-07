<?php

// api/'s webspace and img.die-bestesten.de's webspace are separate, read-only-to-each-other
// mounts on the same hosting account — direct filesystem writes across that boundary are not
// possible, so uploads go over FTPS instead (same account the deploy workflows already use).
class ImageUpload
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    // Input formats we accept, regardless of what the target format for a given field is —
    // uploads get re-encoded server-side, so a .jpg-named file that's actually PNG-encoded
    // (or vice versa) still works.
    private const READERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
    ];

    /** @param string $targetFormat 'png' or 'jpeg' */
    public static function store(array $file, string $relativePath, string $targetFormat): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['status' => false, 'code' => 400, 'message' => 'Keine Datei empfangen'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['status' => false, 'code' => 413, 'message' => 'Datei zu groß'];
        }

        $detectedMime = mime_content_type($file['tmp_name']);
        if (!isset(self::READERS[$detectedMime]) || getimagesize($file['tmp_name']) === false) {
            return ['status' => false, 'code' => 415, 'message' => "Ungültiges Bildformat: '$detectedMime' (erlaubt: JPEG, PNG)"];
        }

        $converted = self::convert($file['tmp_name'], $detectedMime, $targetFormat);
        if ($converted === null) {
            return ['status' => false, 'code' => 500, 'message' => 'Bild konnte nicht verarbeitet werden'];
        }

        $conn = self::connect();
        if (is_array($conn)) { @unlink($converted); return $conn; }

        $result = self::putFile($conn, $converted, $relativePath);
        ftp_close($conn);
        @unlink($converted);
        return $result;
    }

    private static function convert(string $sourceFile, string $sourceMime, string $targetFormat): ?string
    {
        $reader = self::READERS[$sourceMime];
        $image  = @$reader($sourceFile);
        if (!$image) return null;

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        $ok  = $targetFormat === 'jpeg' ? self::writeJpeg($image, $tmp) : imagepng($image, $tmp);
        imagedestroy($image);

        if (!$ok) { @unlink($tmp); return null; }
        return $tmp;
    }

    // JPEG has no alpha channel — flatten onto white so PNG-with-transparency uploads don't
    // come out with black backgrounds.
    private static function writeJpeg($image, string $tmp): bool
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $flat   = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
        $ok = imagejpeg($flat, $tmp, 90);
        imagedestroy($flat);
        return $ok;
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
