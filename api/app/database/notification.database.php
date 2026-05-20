<?php

trait NotificationTrait
{
    public function getNotifications(string $managerId): array
    {
        $q = $this->con_league->prepare(
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
        $q = $this->con_league->prepare(
            "SELECT COUNT(*) FROM notification WHERE receiver_id = ? AND read_at IS NULL"
        );
        $q->execute([$managerId]);
        return (int) $q->fetchColumn();
    }

    public function getNotificationById(string $id): array|false
    {
        $q = $this->con_league->prepare("SELECT * FROM notification WHERE id = ?");
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function markNotificationRead(string $id): void
    {
        $q = $this->con_league->prepare(
            "UPDATE notification SET read_at = NOW() WHERE id = ? AND read_at IS NULL"
        );
        $q->execute([$id]);
    }

    public function markAllNotificationsRead(string $managerId): void
    {
        $q = $this->con_league->prepare(
            "UPDATE notification SET read_at = NOW() WHERE receiver_id = ? AND read_at IS NULL"
        );
        $q->execute([$managerId]);
    }

    public function createNotification(string $receiverId, string $title, ?string $message, ?string $senderId): string
    {
        $id = $this->con_league->query("SELECT UUID()")->fetchColumn();
        $q = $this->con_league->prepare(
            "INSERT INTO notification (id, sender_id, receiver_id, title, message)
             VALUES (?, ?, ?, ?, ?)"
        );
        $q->execute([$id, $senderId, $receiverId, $title, $message]);
        return $id;
    }

    // Preferences

    public function getNotificationPreferences(string $managerId): array
    {
        $defined = ['matchday_completed' => true, 'achievement_earned' => true, 'h2h_draw' => true];
        $q = $this->con_league->prepare(
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
        $this->con_league->prepare(
            "INSERT INTO notification_preference (manager_id, event_type, enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
        )->execute([$managerId, $eventType, $enabled ? 1 : 0]);
    }

    // Bulk notification creators

    public function createMatchdayCompletedNotifications(int $matchdayNumber): void
    {
        $q = $this->con_league->prepare(
            "SELECT id FROM manager WHERE status = 'active'
             AND id NOT IN (
                 SELECT manager_id FROM notification_preference
                 WHERE event_type = 'matchday_completed' AND enabled = 0
             )"
        );
        $q->execute();
        $managerIds = $q->fetchAll(PDO::FETCH_COLUMN);

        if (empty($managerIds)) return;

        $insert = $this->con_league->prepare(
            "INSERT INTO notification (id, receiver_id, title, created_at)
             VALUES (UUID(), ?, ?, NOW())"
        );
        $title = "Spieltag $matchdayNumber abgeschlossen";
        foreach ($managerIds as $managerId) {
            $insert->execute([$managerId, $title]);
        }
    }

    public function createAchievementNotification(string $managerId, string $achievementName, string $level, ?string $reason, ?string $earnedAt = null): void
    {
        $pref = $this->con_league->prepare(
            "SELECT enabled FROM notification_preference
             WHERE manager_id = ? AND event_type = 'achievement_earned'"
        );
        $pref->execute([$managerId]);
        $row = $pref->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && !(bool) $row['enabled']) return;

        $levelLabel = match ($level) { 'bronze' => ' (Bronze)', 'silver' => ' (Silber)', default => '' };
        $title = "Achievement: $achievementName$levelLabel";
        $createdAt = $earnedAt ?? date('Y-m-d H:i:s');
        $this->con_league->prepare(
            "INSERT INTO notification (id, receiver_id, title, message, created_at)
             VALUES (UUID(), ?, ?, ?, ?)"
        )->execute([$managerId, $title, $reason, $createdAt]);
    }
}
