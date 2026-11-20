<?php

/**
 * Tier je Manager (Free/Pro, Tabelle manager_tier, Migration 2026-11-20_manager_tier.sql) — getrennt von den Rollen:
 * Rollen regeln, was jemand verwalten darf, das Tier, welche Features er nutzen darf. Keine Zeile = Free; ein Admin
 * schaltet Pro je Manager an und aus (POST /manager/:id/tier).
 *
 * Features fragen nie "ist er Pro?", sondern nach einem Entitlement (hasEntitlement('…')) — welche Entitlements ein
 * Tier freischaltet, steht nur in tierConfig(). So lässt sich ein Feature später verschieben oder eine weitere Stufe
 * einführen, ohne die Stellen anzufassen, die es abfragen. Fehlt die Tabelle, ist jeder Free.
 */
trait TierTrait
{
    /** Tiers und ihre Entitlements — hier an einer Stelle. Ein Tier erbt nichts automatisch, also vollständig auflisten. */
    protected function tierConfig(): array
    {
        return [
            'free' => [],
            'pro'  => [
                'lineup_form', // Aufstellung: gewichteter Punkteschnitt der letzten 5 Spieltage je Spieler (GET /team_lineup → form)
            ],
        ];
    }

    /** Tier des Managers ('free' ohne Zeile oder ohne Tabelle). */
    public function getManagerTier(string $managerId): string
    {
        try {
            $q = $this->con->prepare("SELECT tier FROM manager_tier WHERE manager_id = ?");
            $q->execute([$managerId]);
            $tier = $q->fetchColumn();
            return $tier !== false && isset($this->tierConfig()[$tier]) ? $tier : 'free';
        } catch (\Throwable $e) {
            return 'free';
        }
    }

    /** Entitlements eines Tiers (unbekanntes Tier = keine). */
    public function tierEntitlements(string $tier): array
    {
        return $this->tierConfig()[$tier] ?? [];
    }

    /** Darf der Manager dieses Feature nutzen? */
    public function managerHasEntitlement(string $managerId, string $entitlement): bool
    {
        return in_array($entitlement, $this->tierEntitlements($this->getManagerTier($managerId)), true);
    }

    /** Alle Manager mit einem Tier außer Free: [manager_id => tier] (für die Manager-Liste der Verwaltung). */
    public function getManagerTiers(): array
    {
        try {
            return $this->con->query("SELECT manager_id, tier FROM manager_tier")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Tier setzen — 'free' löscht die Zeile. Rückgabe false, wenn die Tabelle fehlt (Migration) oder das Tier
     * unbekannt ist.
     */
    public function setManagerTier(string $managerId, string $tier, ?string $grantedBy): bool
    {
        if (!isset($this->tierConfig()[$tier])) return false;
        try {
            if ($tier === 'free') {
                $this->con->prepare("DELETE FROM manager_tier WHERE manager_id = ?")->execute([$managerId]);
            } else {
                $this->con->prepare(
                    "INSERT INTO manager_tier (manager_id, tier, granted_by) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE tier = VALUES(tier), granted_by = VALUES(granted_by), granted_at = NOW()"
                )->execute([$managerId, $tier, $grantedBy]);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('setManagerTier: ' . $e->getMessage());
            return false;
        }
    }
}
