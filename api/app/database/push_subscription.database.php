<?php

trait PushSubscriptionTrait
{
    /**
     * Speichert/aktualisiert das Push-Abo eines Browsers/Geräts für den eingeloggten Manager.
     * Idempotent per UNIQUE(endpoint) — derselbe Browser kann sich beliebig oft erneut anmelden
     * (z.B. nach widerrufener und neu erteilter Berechtigung), ohne Duplikate zu erzeugen;
     * p256dh/auth werden dabei aktualisiert, falls der Browser sie (selten) rotiert.
     */
    public function savePushSubscription(string $managerId, string $endpoint, string $p256dh, string $auth): void
    {
        $this->con->prepare(
            "INSERT INTO push_subscription (id, manager_id, endpoint, p256dh, auth)
             VALUES (UUID(), :manager_id, :endpoint, :p256dh, :auth)
             ON DUPLICATE KEY UPDATE manager_id = VALUES(manager_id), p256dh = VALUES(p256dh), auth = VALUES(auth)"
        )->execute([
            ':manager_id' => $managerId,
            ':endpoint'   => $endpoint,
            ':p256dh'     => $p256dh,
            ':auth'       => $auth,
        ]);
    }

    public function deletePushSubscription(string $managerId, string $endpoint): void
    {
        $this->con->prepare(
            "DELETE FROM push_subscription WHERE manager_id = :manager_id AND endpoint = :endpoint"
        )->execute([':manager_id' => $managerId, ':endpoint' => $endpoint]);
    }

    private function deletePushSubscriptionByEndpoint(string $endpoint): void
    {
        $this->con->prepare(
            "DELETE FROM push_subscription WHERE endpoint = :endpoint"
        )->execute([':endpoint' => $endpoint]);
    }

    /**
     * Sendet eine Web-Push-Benachrichtigung an alle Abos (ggf. mehrere Geräte/Browser) der
     * übergebenen Manager-IDs. Fire-and-forget wie der bestehende mail()-Aufruf in
     * auth.controller.php — ein Fehler beim Senden darf den aufrufenden Request (z.B. das
     * Speichern eines Ratings) nicht blockieren, daher der äußere try/catch. Räumt Abos, die der
     * Push-Dienst als abgelaufen meldet (404/410), automatisch auf.
     */
    public function sendPushNotification(array $managerIds, string $title, string $body, ?string $url = null): void
    {
        if (empty($managerIds) || empty($_ENV['VAPID_PUBLIC_KEY']) || empty($_ENV['VAPID_PRIVATE_KEY'])) return;

        $ph = implode(',', array_fill(0, count($managerIds), '?'));
        $q  = $this->con->prepare("SELECT endpoint, p256dh, auth FROM push_subscription WHERE manager_id IN ($ph)");
        $q->execute(array_values($managerIds));
        $subscriptions = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($subscriptions)) return;

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => 'mailto:noreply@die-bestesten.de',
                    'publicKey'  => $_ENV['VAPID_PUBLIC_KEY'],
                    'privateKey' => $_ENV['VAPID_PRIVATE_KEY'],
                ],
            ]);

            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

            foreach ($subscriptions as $s) {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint'        => $s['endpoint'],
                        'publicKey'       => $s['p256dh'],
                        'authToken'       => $s['auth'],
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    $payload
                );
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    $this->deletePushSubscriptionByEndpoint($report->getEndpoint());
                }
            }
        } catch (\Throwable) {
            // Push-Versand ist best-effort — Fehler dürfen den aufrufenden Request nicht stören
        }
    }
}
