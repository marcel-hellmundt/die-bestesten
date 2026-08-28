<?php

class PushSubscriptionController extends _BaseController
{
    public static array $methodRoles = ['POST' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed { return $this->methodNotAllowed(); }

    protected function post(): mixed
    {
        $body     = $this->body();
        $endpoint = $body['endpoint'] ?? null;
        $keys     = $body['keys'] ?? [];
        $p256dh   = $keys['p256dh'] ?? null;
        $auth     = $keys['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            http_response_code(400);
            return ['status' => false, 'message' => 'endpoint und keys.p256dh/keys.auth erforderlich'];
        }

        $this->db->savePushSubscription($GLOBALS['auth_manager_id'], $endpoint, $p256dh, $auth);

        http_response_code(201);
        return ['status' => true];
    }

    protected function patch(): mixed { return $this->methodNotAllowed(); }

    protected function delete(): mixed
    {
        $endpoint = $this->body()['endpoint'] ?? null;
        if (!$endpoint) {
            http_response_code(400);
            return ['status' => false, 'message' => 'endpoint erforderlich'];
        }

        $this->db->deletePushSubscription($GLOBALS['auth_manager_id'], $endpoint);
        return ['status' => true];
    }
}
