<?php

trait NotificationTrait
{
    public function getNotifications(string $managerId): array
    {
        $q = $this->con->prepare(
            "SELECT n.id, n.sender_id, m.manager_name AS sender_name,
                    n.receiver_id, n.title, n.message, n.created_at, n.read_at
             FROM notification n
             LEFT JOIN manager m ON m.id = n.sender_id
             WHERE n.receiver_id = :receiver_id
             ORDER BY n.created_at DESC"
        );
        $q->execute([':receiver_id' => $managerId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUnreadCount(string $managerId): int
    {
        $q = $this->con->prepare(
            "SELECT COUNT(*) FROM notification WHERE receiver_id = ? AND read_at IS NULL"
        );
        $q->execute([$managerId]);
        return (int) $q->fetchColumn();
    }

    public function getNotificationById(string $id): array|false
    {
        $q = $this->con->prepare("SELECT * FROM notification WHERE id = ?");
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function markNotificationRead(string $id): void
    {
        $q = $this->con->prepare(
            "UPDATE notification SET read_at = NOW() WHERE id = ? AND read_at IS NULL"
        );
        $q->execute([$id]);
    }

    public function markAllNotificationsRead(string $managerId): void
    {
        $q = $this->con->prepare(
            "UPDATE notification SET read_at = NOW() WHERE receiver_id = ? AND read_at IS NULL"
        );
        $q->execute([$managerId]);
    }

    public function createNotification(string $receiverId, string $title, ?string $message, ?string $senderId): string
    {
        $id = $this->con->query("SELECT UUID()")->fetchColumn();
        $q = $this->con->prepare(
            "INSERT INTO notification (id, sender_id, receiver_id, title, message)
             VALUES (?, ?, ?, ?, ?)"
        );
        $q->execute([$id, $senderId, $receiverId, $title, $message]);
        return $id;
    }

    // Preferences

    public function getNotificationPreferences(string $managerId): array
    {
        $defined = ['matchday_completed' => true, 'achievement_earned' => true, 'h2h_draw' => true, 'direct_offer' => true];
        $q = $this->con->prepare(
            "SELECT event_type, enabled FROM notification_preference WHERE manager_id = ?"
        );
        $q->execute([$managerId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['event_type'], $defined)) {
                $defined[$row['event_type']] = (bool) $row['enabled'];
            }
        }
        return $defined;
    }

    public function setNotificationPreference(string $managerId, string $eventType, bool $enabled): void
    {
        $this->con->prepare(
            "INSERT INTO notification_preference (manager_id, event_type, enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
        )->execute([$managerId, $eventType, $enabled ? 1 : 0]);
    }

    // Bulk notification creators

    /**
     * Benachrichtigt nach dem Abschluss eines Spieltags jeden Manager, der in der aktuellen Liga ein Team
     * hat, mit einer persönlichen Zusammenfassung: Titel mit Spieltag + Liganame, Body mit Spieltag/Liga,
     * geholten Punkten, Platz am Spieltag (Standard-Wettkampf-Rang unter den gewerteten Teams) und darunter
     * einer knappen Stats-Zeile. Läuft im Kontext der Liga, in der der Spieltag abgeschlossen wurde
     * (con_league) — ein Manager in mehreren Ligen bekommt so je Liga eine eigene Nachricht. Managern ohne
     * Team in dieser Liga wird nichts geschickt (sie haben an dem Spieltag nichts geholt).
     */
    public function createMatchdayCompletedNotifications(string $matchdayId, int $matchdayNumber): void
    {
        $rq = $this->con_league->prepare(
            "SELECT t.manager_id, tr.points, tr.goals, tr.assists, tr.red_cards, tr.yellow_red_cards,
                    tr.clean_sheet, tr.sds, tr.invalid
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             WHERE tr.matchday_id = ?"
        );
        $rq->execute([$matchdayId]);
        $rows = $rq->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return;

        // Nur Manager, die aktiv sind und diese Benachrichtigung nicht abgeschaltet haben
        $q = $this->con->prepare(
            "SELECT id FROM manager WHERE status = 'active'
             AND id NOT IN (
                 SELECT manager_id FROM notification_preference
                 WHERE event_type = 'matchday_completed' AND enabled = 0
             )"
        );
        $q->execute();
        $enabled = array_flip($q->fetchAll(PDO::FETCH_COLUMN));

        $leagueName = $this->getCurrentLeagueName();
        $title = "Spieltag $matchdayNumber abgeschlossen" . ($leagueName ? " – $leagueName" : '');

        // Platz am Spieltag: Standard-Wettkampf-Rang (1224) unter den gewerteten (nicht ungültigen) Teams
        $valid       = array_values(array_filter($rows, fn($r) => !(bool) $r['invalid']));
        $validCount  = count($valid);
        $rankOf      = function (int $points) use ($valid): int {
            return 1 + count(array_filter($valid, fn($r) => (int) $r['points'] > $points));
        };

        $insert = $this->con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message, created_at)
             VALUES (UUID(), ?, ?, ?, NOW())"
        );
        foreach ($rows as $r) {
            if (!isset($enabled[$r['manager_id']])) continue;

            $lead = "Spieltag $matchdayNumber" . ($leagueName ? " in der Liga „{$leagueName}“" : '') . ' wurde abgeschlossen.';
            if ((bool) $r['invalid']) {
                $body = "$lead\n\nDein Team wurde für diesen Spieltag nicht gewertet (ungültige Aufstellung).";
            } else {
                $points = (int) $r['points'];
                $cards  = [];
                if ((int) $r['red_cards'] > 0)        $cards[] = (int) $r['red_cards'] . '× Rot';
                if ((int) $r['yellow_red_cards'] > 0) $cards[] = (int) $r['yellow_red_cards'] . '× Gelb-Rot';
                $stats = 'SdS: ' . (int) $r['sds']
                    . ' · Tore: ' . (int) $r['goals']
                    . ' · Assists: ' . (int) $r['assists']
                    . ' · Weiße Westen: ' . (int) $r['clean_sheet']
                    . ' · Karten: ' . (empty($cards) ? '0' : implode(', ', $cards));
                $body = "$lead\n\nDu hast $points " . ($points === 1 ? 'Punkt' : 'Punkte') . ' geholt und damit Platz '
                    . $rankOf($points) . " von $validCount belegt.\n\n$stats";
            }
            $insert->execute([$r['manager_id'], $title, $body]);
        }
    }

    /** Name der aktuellen Liga (aus dem JWT, sonst die per DB_NAME_LEAGUE konfigurierte Deployment-Liga). */
    private function getCurrentLeagueName(): ?string
    {
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if ($leagueId) {
            $q = $this->con->prepare("SELECT name FROM league WHERE id = :id LIMIT 1");
            $q->execute([':id' => $leagueId]);
        } else {
            $q = $this->con->prepare("SELECT name FROM league WHERE db_name = :db_name LIMIT 1");
            $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE'] ?? '']);
        }
        return $q->fetchColumn() ?: null;
    }

    public function createAchievementNotification(string $managerId, string $achievementName, string $level, ?string $reason, ?string $earnedAt = null): void
    {
        $pref = $this->con->prepare(
            "SELECT enabled FROM notification_preference
             WHERE manager_id = ? AND event_type = 'achievement_earned'"
        );
        $pref->execute([$managerId]);
        $row = $pref->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && !(bool) $row['enabled']) return;

        $levelLabel = match ($level) { 'bronze' => ' (Bronze)', 'silver' => ' (Silber)', default => '' };
        $title = "Achievement: $achievementName$levelLabel";
        $createdAt = $earnedAt ?? date('Y-m-d H:i:s');
        $this->con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message, created_at)
             VALUES (UUID(), ?, ?, ?, ?)"
        )->execute([$managerId, $title, $reason, $createdAt]);
    }

    // Zur besseren Kontrolle sollen Admins IMMER mitbekommen, wenn irgendein Manager ein
    // Achievement bekommt — unabhängig von dessen eigener 'achievement_earned'-Präferenz (die
    // gilt nur für die Benachrichtigung des Empfängers selbst, siehe createAchievementNotification
    // oben). Der Empfänger wird aus der Admin-Liste ausgenommen, falls er selbst Admin ist — sonst
    // bekäme er dieselbe Nachricht doppelt (einmal als Empfänger, einmal als Admin).
    public function notifyAdminsOfAchievement(string $earnerManagerId, string $earnerName, string $achievementName, string $level, ?string $reason, ?string $earnedAt = null): void
    {
        $adminIds = array_diff($this->getAdminManagerIds(), [$earnerManagerId]);
        if (empty($adminIds)) return;

        $levelLabel = match ($level) { 'bronze' => ' (Bronze)', 'silver' => ' (Silber)', default => '' };
        $title = "Achievement vergeben: $earnerName – $achievementName$levelLabel";
        $createdAt = $earnedAt ?? date('Y-m-d H:i:s');
        $insert = $this->con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message, created_at)
             VALUES (UUID(), ?, ?, ?, ?)"
        );
        foreach ($adminIds as $adminId) {
            $insert->execute([$adminId, $title, $reason, $createdAt]);
        }
    }
}
