<?php

/**
 * Erscheinungs-Einstellungen je Nutzer (siehe AppearanceTrait):
 *   GET   /appearance — alle Einstellungen mit Wert (Auth)
 *   PATCH /appearance — eine Einstellung setzen {key, enabled} (Auth)
 */
class AppearanceController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'PATCH' => 'manager', 'DELETE' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id !== null) return $this->methodNotAllowed();
        return $this->db->getAppearancePreferences($GLOBALS['auth_manager_id']);
    }

    protected function patch(): mixed
    {
        if ($this->id !== null) return $this->methodNotAllowed();

        $body    = $this->body();
        $key     = $body['key'] ?? null;
        $enabled = $body['enabled'] ?? null;
        if (!is_string($key) || !$this->db->isAppearanceKey($key) || $enabled === null) {
            http_response_code(422);
            return ['status' => false, 'message' => 'key (bekannte Einstellung) und enabled (bool) erforderlich'];
        }
        if (!$this->db->setAppearancePreference($GLOBALS['auth_manager_id'], $key, (bool) $enabled)) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Erscheinungs-Einstellungen sind noch nicht eingerichtet'];
        }
        return ['status' => true];
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
