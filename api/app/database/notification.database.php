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
}
