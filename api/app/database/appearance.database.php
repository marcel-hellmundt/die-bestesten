<?php

/**
 * Erscheinungs-Einstellungen je Nutzer (Tabelle appearance_preference, globale DB) — wie die App für diesen Manager
 * aussieht, unabhängig vom Gerät. Bewusst getrennt von notification_preference (Benachrichtigungen, Einblendungen).
 * Bisher: welche Einträge rechts in der Topbar stehen, getrennt für Desktop und Handy
 * (topbar_{desktop|mobile}_{lukaten|karte|klebrigsten|achievements|benachrichtigungen}). Was nicht in der Topbar
 * steht, liegt im Benutzermenü. Gespeichert wird nur, was vom Standard abweichen kann; fehlt eine Zeile, gilt der
 * Standard aus appearanceDefaults(). Hell/Dunkel bleibt pro Gerät (localStorage, ThemeService).
 */
trait AppearanceTrait
{
    /** Alle Einstellungen mit Standardwert: am Desktop alle Einträge in der Topbar, auf dem Handy nur Lukaten. */
    private function appearanceDefaults(): array
    {
        $defaults = [];
        foreach (['lukaten', 'karte', 'klebrigsten', 'achievements', 'benachrichtigungen'] as $item) {
            $defaults["topbar_desktop_$item"] = true;
            $defaults["topbar_mobile_$item"]  = $item === 'lukaten';
        }
        return $defaults;
    }

    public function isAppearanceKey(string $key): bool
    {
        return array_key_exists($key, $this->appearanceDefaults());
    }

    /** GET /appearance — alle Einstellungen des Managers; ohne Tabelle (Migration fehlt) die Standardwerte. */
    public function getAppearancePreferences(string $managerId): array
    {
        $prefs = $this->appearanceDefaults();
        try {
            $q = $this->con->prepare("SELECT pref_key, enabled FROM appearance_preference WHERE manager_id = ?");
            $q->execute([$managerId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (array_key_exists($row['pref_key'], $prefs)) $prefs[$row['pref_key']] = (bool) $row['enabled'];
            }
        } catch (\Throwable $e) {
            // Tabelle fehlt noch → Standard
        }
        return $prefs;
    }

    /** PATCH /appearance — eine Einstellung setzen; false, wenn die Tabelle fehlt. */
    public function setAppearancePreference(string $managerId, string $key, bool $enabled): bool
    {
        try {
            $this->con->prepare(
                "INSERT INTO appearance_preference (manager_id, pref_key, enabled)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
            )->execute([$managerId, $key, $enabled ? 1 : 0]);
            return true;
        } catch (\Throwable $e) {
            error_log('setAppearancePreference: ' . $e->getMessage());
            return false;
        }
    }
}
