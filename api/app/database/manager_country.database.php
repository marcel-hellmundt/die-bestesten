<?php

/**
 * Länderpunkte auf der Karte (/karte): welche Länder ein Manager als besucht markiert hat.
 * Tabelle manager_country (Migration 2026-09-28_manager_country.sql) — fehlt sie noch,
 * liefert GET eine leere Liste statt eines Fehlers.
 */
trait ManagerCountryTrait
{
    public function getVisitedCountryIds(string $managerId): array
    {
        try {
            $query = $this->con->prepare("SELECT country_id FROM manager_country WHERE manager_id = :manager_id");
            $query->execute([':manager_id' => $managerId]);
            return $query->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Länderpunkte aller (nicht gelöschten) Manager fürs Ranking auf der Karte →
     * [{manager_id, manager_name, countries:[country_id]}], nur Manager mit ≥ 1 Land; [] ohne Migration.
     */
    public function getAllManagerCountries(): array
    {
        try {
            $q = $this->con->prepare(
                "SELECT mc.manager_id, m.manager_name, mc.country_id
                 FROM manager_country mc JOIN manager m ON m.id = mc.manager_id
                 WHERE m.status != 'deleted'
                 ORDER BY m.manager_name, mc.created_at"
            );
            $q->execute();
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['manager_id']] ??= ['manager_id' => $r['manager_id'], 'manager_name' => $r['manager_name'], 'countries' => []];
            $out[$r['manager_id']]['countries'][] = $r['country_id'];
        }
        return array_values($out);
    }

    /** false, wenn es das Land nicht gibt */
    public function markCountryVisited(string $managerId, string $countryId): bool
    {
        $c = $this->con->prepare("SELECT id FROM country WHERE id = :id LIMIT 1");
        $c->execute([':id' => $countryId]);
        $id = $c->fetchColumn();
        if ($id === false) return false;

        $query = $this->con->prepare(
            "INSERT IGNORE INTO manager_country (id, manager_id, country_id) VALUES (UUID(), :manager_id, :country_id)"
        );
        $query->execute([':manager_id' => $managerId, ':country_id' => $id]);
        return true;
    }

    public function unmarkCountryVisited(string $managerId, string $countryId): void
    {
        $query = $this->con->prepare(
            "DELETE FROM manager_country WHERE manager_id = :manager_id AND country_id = :country_id"
        );
        $query->execute([':manager_id' => $managerId, ':country_id' => $countryId]);
    }

    /** Beim Markieren eines Stadions: Land des (aktuellen) Vereins still mitgutschreiben (fehlt die Tabelle: nichts). */
    public function creditCountryOfStadium(string $managerId, string $stadiumId): void
    {
        try {
            $query = $this->con->prepare(
                "INSERT IGNORE INTO manager_country (id, manager_id, country_id)
                 SELECT UUID(), :manager_id, c.country_id
                 FROM club_stadium cs JOIN club c ON c.id = cs.club_id
                 WHERE cs.stadium_id = :stadium_id AND c.country_id IS NOT NULL
                 ORDER BY cs.to_date IS NULL DESC, cs.from_date DESC
                 LIMIT 1"
            );
            $query->execute([':manager_id' => $managerId, ':stadium_id' => $stadiumId]);
        } catch (PDOException $e) {
            // Tabelle manager_country noch nicht angelegt — Stadion-Markierung selbst bleibt davon unberührt
        }
    }
}
