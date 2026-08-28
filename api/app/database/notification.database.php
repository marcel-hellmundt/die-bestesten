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

    // Preferences — welche event_types welche Channels unterstützen (nicht jedes Event ist auch
    // als Push verfügbar). Einzige Quelle der Wahrheit für sowohl die Default-Struktur von
    // getNotificationPreferences() als auch die Validierung in
    // NotificationController::patch()/isValidPreferenceCombo().
    private const NOTIFICATION_CHANNELS = [
        'matchday_completed'    => ['in_app'],
        'achievement_earned'    => ['in_app', 'push'],
        'h2h_draw'              => ['in_app'],
        'scouted_player_update' => ['in_app', 'push'],
        'lineup_player_goal'    => ['in_app', 'push'],
    ];

    public function isValidPreferenceCombo(string $channel, string $eventType): bool
    {
        return in_array($channel, self::NOTIFICATION_CHANNELS[$eventType] ?? [], true);
    }

    public function getNotificationPreferences(string $managerId): array
    {
        $result = ['in_app' => [], 'push' => []];
        foreach (self::NOTIFICATION_CHANNELS as $eventType => $channels) {
            foreach ($channels as $channel) {
                $result[$channel][$eventType] = true;
            }
        }

        $q = $this->con->prepare(
            "SELECT channel, event_type, enabled FROM notification_preference WHERE manager_id = ?"
        );
        $q->execute([$managerId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($result[$row['channel']][$row['event_type']])) {
                $result[$row['channel']][$row['event_type']] = (bool) $row['enabled'];
            }
        }
        return $result;
    }

    public function setNotificationPreference(string $managerId, string $channel, string $eventType, bool $enabled): void
    {
        $this->con->prepare(
            "INSERT INTO notification_preference (manager_id, channel, event_type, enabled)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
        )->execute([$managerId, $channel, $eventType, $enabled ? 1 : 0]);
    }

    public function isNotificationEnabled(string $managerId, string $channel, string $eventType): bool
    {
        $q = $this->con->prepare(
            "SELECT enabled FROM notification_preference WHERE manager_id = ? AND channel = ? AND event_type = ?"
        );
        $q->execute([$managerId, $channel, $eventType]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false || (bool) $row['enabled'];
    }

    // Bulk notification creators

    public function createMatchdayCompletedNotifications(int $matchdayNumber): void
    {
        $q = $this->con->prepare(
            "SELECT id FROM manager WHERE status = 'active'
             AND id NOT IN (
                 SELECT manager_id FROM notification_preference
                 WHERE channel = 'in_app' AND event_type = 'matchday_completed' AND enabled = 0
             )"
        );
        $q->execute();
        $managerIds = $q->fetchAll(PDO::FETCH_COLUMN);

        if (empty($managerIds)) return;

        $insert = $this->con->prepare(
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
        $levelLabel = match ($level) { 'bronze' => ' (Bronze)', 'silver' => ' (Silber)', default => '' };
        $title = "Achievement: $achievementName$levelLabel";

        if ($this->isNotificationEnabled($managerId, 'in_app', 'achievement_earned')) {
            $createdAt = $earnedAt ?? date('Y-m-d H:i:s');
            $this->con->prepare(
                "INSERT INTO notification (id, receiver_id, title, message, created_at)
                 VALUES (UUID(), ?, ?, ?, ?)"
            )->execute([$managerId, $title, $reason, $createdAt]);
        }

        if ($this->isNotificationEnabled($managerId, 'push', 'achievement_earned')) {
            $this->sendPushNotification([$managerId], $title, $reason ?? 'Neues Achievement freigeschaltet!');
        }
    }
}
