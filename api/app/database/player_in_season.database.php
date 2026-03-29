<?php

trait PlayerInSeasonTrait
{
    /**
     * Anzahl der Spieler in der 1. Bundesliga (Level 1, country_id 'de') einer Saison.
     * Wenn keine season_id übergeben wird, wird die aktive Saison verwendet.
     */
    public function getBundesligaPlayerCount(?string $seasonId): int
    {
        if (!$seasonId) {
            $stmt = $this->con->query("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 0;
            $seasonId = $row['id'];
        }

        $query = $this->con->prepare(
            "SELECT COUNT(DISTINCT pis.player_id) AS cnt
             FROM player_in_season pis
             JOIN club_in_season cis ON cis.club_id = pis.club_id AND cis.season_id = pis.season_id
             JOIN division d ON d.id = cis.division_id
             WHERE pis.season_id = :season_id
               AND d.level = 1
               AND LOWER(d.country_id) = 'de'"
        );
        $query->execute([':season_id' => $seasonId]);
        return (int) $query->fetchColumn();
    }
}
