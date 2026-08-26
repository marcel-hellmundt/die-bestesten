<?php

trait PlayerInTeamTrait
{
    public function getSquadByTeamId(string $teamId): array
    {
        // Step 1: get player IDs + season_id from league DB
        $q = $this->con_league->prepare(
            "SELECT pit.player_id, t.season_id
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.team_id = :team_id AND pit.to_matchday_id IS NULL"
        );
        $q->execute([':team_id' => $teamId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [];

        $seasonId  = $rows[0]['season_id'];
        $playerIds = array_column($rows, 'player_id');

        return $this->markDrafted($this->fetchPlayerDetails($playerIds, $seasonId), $teamId);
    }

    /**
     * transaction hat keine player_id-Spalte — Zuordnung zu einem Spieler läuft im ganzen Codebase
     * über reason-Text + team_id (+ matchday_id, siehe getTeamHistoryByPlayerId's price_paid).
     * Für die Zulosung (seit Saison 2026/27, siehe assignDraftPlayers) reicht ein reiner
     * displayname-Abgleich auf reason='Draft-Zuweisung: {displayname}' — daher hier ohne
     * matchday_id-Filter, um Zulosung auch im ehemaligen Kader (former squad) markieren zu können.
     * Wie bei price_paid gilt: wird ein Spieler nachträglich umbenannt (PATCH /player/:id), erkennt
     * dieser reason-Text-Abgleich ihn nicht mehr — bekannte, im Codebase bereits existierende
     * Einschränkung dieses Musters, keine neue.
     */
    private function markDrafted(array $players, string $teamId): array
    {
        if (empty($players)) return $players;

        $q = $this->con_league->prepare(
            "SELECT DISTINCT SUBSTRING(reason, LENGTH('Draft-Zuweisung: ') + 1) AS displayname
             FROM transaction
             WHERE team_id = :team_id AND reason LIKE 'Draft-Zuweisung: %'"
        );
        $q->execute([':team_id' => $teamId]);
        $draftedNames = array_flip($q->fetchAll(PDO::FETCH_COLUMN));

        foreach ($players as &$p) {
            $p['is_drafted'] = isset($draftedNames[$p['displayname']]);
        }
        unset($p);
        return $players;
    }

    public function getFormerSquadByTeamId(string $teamId): array
    {
        // Get season_id from team
        $tq = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
        $tq->execute([':id' => $teamId]);
        $team = $tq->fetch(PDO::FETCH_ASSOC);
        if (!$team) return [];
        $seasonId = $team['season_id'];

        // Active player_ids for exclusion
        $aq = $this->con_league->prepare(
            "SELECT player_id FROM player_in_team WHERE team_id = :team_id AND to_matchday_id IS NULL"
        );
        $aq->execute([':team_id' => $teamId]);
        $activeIds = array_column($aq->fetchAll(PDO::FETCH_ASSOC), 'player_id');

        // Former: sold players not currently active
        $fq = $this->con_league->prepare(
            "SELECT DISTINCT player_id FROM player_in_team
             WHERE team_id = :team_id AND to_matchday_id IS NOT NULL"
        );
        $fq->execute([':team_id' => $teamId]);
        $formerIds = array_column($fq->fetchAll(PDO::FETCH_ASSOC), 'player_id');

        // Exclude re-bought players
        $formerIds = array_values(array_diff($formerIds, $activeIds));
        if (empty($formerIds)) return [];

        return $this->markDrafted($this->fetchPlayerDetails($formerIds, $seasonId), $teamId);
    }

    public function getTeamByPlayerId(string $playerId): ?array
    {
        $activeSeasonId = $this->getActiveSeasonId();
        if (!$activeSeasonId) return null;

        $q = $this->con_league->prepare(
            "SELECT t.id, t.season_id, t.team_name, t.color_primary AS color, t.manager_id, m.manager_name, m.alias
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE pit.player_id = :player_id
               AND pit.to_matchday_id IS NULL
               AND t.season_id = :season_id
             LIMIT 1"
        );
        $q->execute([':player_id' => $playerId, ':season_id' => $activeSeasonId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) $row['color'] = $this->resolveColor($row['color']);
        return $row ?: null;
    }

    public function getTeamHistoryByPlayerId(string $playerId, string $seasonId): array
    {
        $dnq = $this->con->prepare("SELECT displayname FROM player WHERE id = :id LIMIT 1");
        $dnq->execute([':id' => $playerId]);
        $displayname = $dnq->fetchColumn() ?: '';

        $q = $this->con_league->prepare(
            "SELECT pit.from_matchday_id, pit.to_matchday_id,
                    t.id AS team_id, t.season_id, t.team_name, t.color_primary AS color,
                    m.manager_name, m.alias, tr.amount AS price_paid_amount
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             JOIN manager m ON m.id = t.manager_id
             LEFT JOIN transaction tr
                    ON tr.team_id = pit.team_id
                   AND tr.matchday_id = pit.from_matchday_id
                   AND tr.reason IN (
                         CONCAT('Spielerkauf: ', :dn1),
                         CONCAT('Spielerkauf (Gebot): ', :dn2),
                         CONCAT('Draft-Zuweisung: ', :dn3)
                       )
             WHERE pit.player_id = :player_id AND t.season_id = :season_id
             ORDER BY pit.from_matchday_id"
        );
        $q->execute([
            ':dn1'       => $displayname,
            ':dn2'       => $displayname,
            ':dn3'       => $displayname,
            ':player_id' => $playerId,
            ':season_id' => $seasonId,
        ]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [];

        // Collect all matchday IDs to resolve numbers in one query
        $matchdayIds = [];
        foreach ($rows as $row) {
            if ($row['from_matchday_id']) $matchdayIds[] = $row['from_matchday_id'];
            if ($row['to_matchday_id'])   $matchdayIds[] = $row['to_matchday_id'];
        }
        $matchdayIds = array_values(array_unique($matchdayIds));

        $numbers = [];
        if (!empty($matchdayIds)) {
            $ph = implode(',', array_fill(0, count($matchdayIds), '?'));
            $mq = $this->con->prepare("SELECT id, number FROM matchday WHERE id IN ($ph)");
            $mq->execute($matchdayIds);
            foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $numbers[$m['id']] = (int) $m['number'];
            }
        }

        return array_map(fn($row) => [
            'team_id'              => $row['team_id'],
            'season_id'            => $row['season_id'],
            'team_name'            => $row['team_name'],
            'color'                => $this->resolveColor($row['color']),
            'manager_name'         => $row['manager_name'],
            'alias'                => $row['alias'],
            'from_matchday_number' => $row['from_matchday_id'] ? ($numbers[$row['from_matchday_id']] ?? null) : null,
            'to_matchday_number'   => $row['to_matchday_id']   ? ($numbers[$row['to_matchday_id']]   ?? null) : null,
            'price_paid'           => $row['price_paid_amount'] !== null ? abs((float) $row['price_paid_amount']) : null,
        ], $rows);
    }

    private function fetchPlayerDetails(array $playerIds, string $seasonId): array
    {
        $ph = implode(',', array_fill(0, count($playerIds), '?'));
        $q  = $this->con->prepare(
            "SELECT p.id, p.displayname, p.country_id,
                    pis.position, pis.price, pis.photo_uploaded,
                    ? AS season_id,
                    COALESCE(SUM(pr.points), 0) AS points,
                    pic.club_id AS current_club_id,
                    c.logo_uploaded AS club_logo_uploaded
             FROM player p
             LEFT JOIN player_in_season pis
                   ON pis.player_id = p.id AND pis.season_id = ?
             LEFT JOIN player_rating pr
                   ON pr.player_id = p.id
                   AND pr.matchday_id IN (SELECT id FROM matchday WHERE season_id = ?)
             LEFT JOIN player_in_club pic
                   ON pic.player_id = p.id AND pic.to_date IS NULL
             LEFT JOIN club c
                   ON c.id = pic.club_id
             WHERE p.id IN ($ph)
             GROUP BY p.id, p.displayname, p.country_id, pis.position, pis.price, pis.photo_uploaded,
                      pic.club_id, c.logo_uploaded
             ORDER BY FIELD(pis.position, 'GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'),
                      points DESC,
                      pis.price DESC"
        );
        $q->execute(array_merge([$seasonId, $seasonId, $seasonId], $playerIds));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
