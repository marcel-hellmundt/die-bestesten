<?php

class NotificationController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'admin', 'PATCH' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id === 'preferences') {
            return $this->db->getNotificationPreferences($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'unread_count') {
            return ['count' => $this->db->getUnreadCount($GLOBALS['auth_manager_id'])];
        }
        return $this->db->getNotifications($GLOBALS['auth_manager_id']);
    }

    protected function post(): mixed
    {
        $body       = $this->body();
        $receiverId = $body['receiver_id'] ?? null;
        $title      = $body['title']       ?? null;
        if (!$receiverId || !$title) {
            http_response_code(422);
            return ['message' => 'receiver_id und title erforderlich'];
        }
        $id = $this->db->createNotification(
            $receiverId,
            $title,
            $body['message'] ?? null,
            $body['sender_id'] ?? null
        );
        return ['id' => $id];
    }

    protected function patch(): mixed
    {
        $managerId = $GLOBALS['auth_manager_id'];

        if ($this->id === 'preferences') {
            $body      = $this->body();
            $eventType = $body['event_type'] ?? null;
            $enabled   = $body['enabled']    ?? null;
            $allowed   = ['matchday_completed', 'achievement_earned', 'h2h_draw'];
            if (!$eventType || !in_array($eventType, $allowed) || $enabled === null) {
                http_response_code(422);
                return ['message' => 'event_type (matchday_completed|achievement_earned|h2h_draw) und enabled (bool) erforderlich'];
            }
            $this->db->setNotificationPreference($managerId, $eventType, (bool) $enabled);
            return ['ok' => true];
        }

        if ($this->id === 'read_all') {
            $this->db->markAllNotificationsRead($managerId);
            return ['ok' => true];
        }
        $notif = $this->db->getNotificationById($this->id);
        if (!$notif || $notif['receiver_id'] !== $managerId) {
            http_response_code(403);
            return ['message' => 'Forbidden'];
        }
        $this->db->markNotificationRead($this->id);
        return ['ok' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
