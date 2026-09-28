<?php

/**
 * Länderpunkte auf der Karte (/karte): welche Länder ein Manager als besucht markiert hat.
 * Tabelle manager_country (Migration database/migrations/2026-09-28_manager_country.sql) — fehlt sie noch,
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
