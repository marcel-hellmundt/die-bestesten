<?php

trait PasswordResetTrait
{
    public function getManagerByEmail(string $email): array|false
    {
        $q = $this->con_league->prepare(
            "SELECT id, manager_name, alias FROM manager WHERE email = :email AND status = 'active' LIMIT 1"
        );
        $q->execute([':email' => $email]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function createPasswordResetToken(string $managerId): string
    {
        // Invalidate any existing unused tokens for this manager
        $q = $this->con_league->prepare(
            "UPDATE password_reset_token SET used = 1 WHERE manager_id = :manager_id AND used = 0"
        );
        $q->execute([':manager_id' => $managerId]);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $q = $this->con_league->prepare(
            "INSERT INTO password_reset_token (manager_id, token_hash, expires_at)
             VALUES (:manager_id, :token_hash, :expires_at)"
        );
        $q->execute([
            ':manager_id' => $managerId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);

        return $plainToken;
    }

    public function consumePasswordResetToken(string $plainToken, string $newHashedPassword): bool
    {
        $tokenHash = hash('sha256', $plainToken);

        $q = $this->con_league->prepare(
            "SELECT id, manager_id FROM password_reset_token
             WHERE token_hash = :hash AND used = 0 AND expires_at > NOW() LIMIT 1"
        );
        $q->execute([':hash' => $tokenHash]);
        $row = $q->fetch(PDO::FETCH_ASSOC);

        if (!$row) return false;

        $q = $this->con_league->prepare(
            "UPDATE password_reset_token SET used = 1 WHERE id = :id"
        );
        $q->execute([':id' => $row['id']]);

        $this->updateManagerPassword($row['manager_id'], $newHashedPassword);

        return true;
    }
}
