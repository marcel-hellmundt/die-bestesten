<?php

/**
 * Request-Limit für Login und Passwort-Reset (Tabelle auth_attempt, Migration 2026-11-04_auth_attempt.sql).
 * Gezählt werden fehlgeschlagene Logins und angeforderte Reset-Links im Zeitfenster, je eingegebenem Namen bzw. je
 * E-Mail und je IP — das eine bremst Durchprobieren eines Kontos, das andere Durchprobieren vieler Konten von einer
 * Adresse. Fehlt die Tabelle, gibt es kein Limit (alles erlaubt, nichts geschrieben).
 */
trait AuthAttemptTrait
{
    /** Grenzen — hier an einer Stelle. */
    protected function authAttemptConfig(): array
    {
        return [
            'login' => ['window_minutes' => 15, 'max_per_identifier' => 10, 'max_per_ip' => 30],
            'reset' => ['window_minutes' => 60, 'max_per_identifier' => 3,  'max_per_ip' => 10],
        ];
    }

    private function authAttemptIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** Zu viele Versuche dieser Art im Zeitfenster (je Name/E-Mail oder je IP)? */
    public function isAuthAttemptLimited(string $kind, string $identifier): bool
    {
        $cfg = $this->authAttemptConfig()[$kind];
        try {
            $q = $this->con->prepare(
                "SELECT
                    COALESCE(SUM(identifier = :identifier), 0) AS by_identifier,
                    COALESCE(SUM(ip = :ip), 0) AS by_ip
                 FROM auth_attempt
                 WHERE kind = :kind AND created_at > NOW() - INTERVAL :minutes MINUTE
                   AND (identifier = :identifier2 OR ip = :ip2)"
            );
            $id = mb_strtolower(trim($identifier));
            $ip = $this->authAttemptIp();
            $q->execute([':identifier' => $id, ':identifier2' => $id, ':ip' => $ip, ':ip2' => $ip,
                         ':kind' => $kind, ':minutes' => $cfg['window_minutes']]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            return (int) $r['by_identifier'] >= $cfg['max_per_identifier'] || (int) $r['by_ip'] >= $cfg['max_per_ip'];
        } catch (\Throwable $e) {
            return false; // Tabelle fehlt (Migration) → kein Limit
        }
    }

    /** Einen Versuch festhalten; räumt dabei Zeilen älter als einen Tag weg. */
    public function recordAuthAttempt(string $kind, string $identifier): void
    {
        try {
            $this->con->prepare("INSERT INTO auth_attempt (kind, identifier, ip) VALUES (?, ?, ?)")
                ->execute([$kind, mb_substr(mb_strtolower(trim($identifier)), 0, 255), $this->authAttemptIp()]);
            $this->con->exec("DELETE FROM auth_attempt WHERE created_at < NOW() - INTERVAL 1 DAY");
        } catch (\Throwable $e) {
            // Tabelle fehlt (Migration) → nichts festhalten
        }
    }

    /** Nach erfolgreichem Login: die Fehlversuche dieses Namens zählen nicht mehr. */
    public function clearAuthAttempts(string $kind, string $identifier): void
    {
        try {
            $this->con->prepare("DELETE FROM auth_attempt WHERE kind = ? AND identifier = ?")
                ->execute([$kind, mb_strtolower(trim($identifier))]);
        } catch (\Throwable $e) {
            // Tabelle fehlt (Migration)
        }
    }
}
