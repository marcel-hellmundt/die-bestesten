<?php

trait PlayerInSeasonTrait
{
    /**
     * All bundesliga players not currently in any fantasy team — usable as a "free agent market".
     * Returns player info, position, price, cumulative season points, and club data.
     */
    public function getAvailablePlayers(?string $seasonId): array
    {
        if (!$seasonId) {
            $row = $this->con->query("SELECT id FROM season ORDER BY start_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['players' => []];
            $seasonId = $row['id'];
        }

        // Get player IDs already in a fantasy team this season (league DB)
        $excludedIds = [];
        try {
            $ex = $this->con_league->prepare(
                "SELECT DISTINCT pit.player_id
                 FROM player_in_team pit
                 JOIN team t ON t.id = pit.team_id
                 WHERE t.season_id = ? AND pit.to_matchday_id IS NULL"
            );
            $ex->execute([$seasonId]);
            $excludedIds = $ex->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {}

        $exclusionClause  = '';
        $exclusionParams  = [];
        if (!empty($excludedIds)) {
            $ph              = implode(',', array_fill(0, count($excludedIds), '?'));
            $exclusionClause = "AND p.id NOT IN ($ph)";
            $exclusionParams = $excludedIds;
        }

        $stmt = $this->con->prepare(
            "SELECT p.id, p.displayname,
                    pis.position, pis.price, pis.photo_uploaded,
                    pic.club_id,
                    c.name AS club_name, c.short_name AS club_short_name,
                    c.logo_uploaded AS club_logo_uploaded,
                    COALESCE(SUM(pr.points), 0) AS season_points
             FROM player_in_season pis
             JOIN player p          ON p.id = pis.player_id
             JOIN player_in_club pic ON pic.player_id = p.id AND pic.to_date IS NULL
             JOIN club c            ON c.id = pic.club_id
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
             JOIN division d        ON d.id = cis.division_id
             LEFT JOIN player_rating pr ON pr.player_id = p.id
                 AND pr.matchday_id IN (SELECT id FROM matchday WHERE season_id = ?)
             WHERE pis.season_id = ?
               AND d.level = 1
               AND LOWER(d.country_id) = 'de'
               $exclusionClause
             GROUP BY p.id, p.displayname, pis.position, pis.price, pis.photo_uploaded,
                      pic.club_id, c.name, c.short_name, c.logo_uploaded
             ORDER BY FIELD(pis.position, 'GOALKEEPER','DEFENDER','MIDFIELDER','FORWARD'),
                      pis.price DESC"
        );
        $stmt->execute(array_merge([$seasonId, $seasonId], $exclusionParams));

        return ['players' => array_map(fn($r) => [
            'id'                 => $r['id'],
            'displayname'        => $r['displayname'],
            'position'           => $r['position'],
            'price'              => (int) $r['price'],
            'season_points'      => (int) $r['season_points'],
            'photo_uploaded'     => (bool) $r['photo_uploaded'],
            'club_id'            => $r['club_id'],
            'club_name'          => $r['club_name'],
            'club_short_name'    => $r['club_short_name'],
            'club_logo_uploaded' => (bool) $r['club_logo_uploaded'],
            'season_id'          => $seasonId,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    /**
     * Count of players in the 1. Bundesliga (level 1, country_id 'de') for a season.
     * If no season_id is provided, the active season is used.
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
             JOIN player_in_club pic ON pic.player_id = pis.player_id AND pic.to_date IS NULL
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
             JOIN division d ON d.id = cis.division_id
             WHERE pis.season_id = :season_id
               AND d.level = 1
               AND LOWER(d.country_id) = 'de'"
        );
        $query->execute([':season_id' => $seasonId]);
        return (int) $query->fetchColumn();
    }
}
