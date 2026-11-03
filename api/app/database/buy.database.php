<?php

/**
 * Prüfungen rund um den Kauf eines vereinslosen Spielers — genutzt von Gebot und Auswertung
 * (OfferTrait::submitOffer(), settleWindow()). Einen Direktkauf zum Grundpreis gibt es nicht mehr:
 * POST /buy umging Auktion und Budget, und der Verkauf zahlt Grundpreis + Saisonpunkte × points_bonus.
 */
trait BuyTrait
{
    public function isPlayerAlreadyInAnyTeam(string $playerId): bool
    {
        $activeSeasonId = $this->getActiveSeasonId();
        if (!$activeSeasonId) return false;

        $q = $this->con_league->prepare(
            "SELECT pit.id FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.player_id = :pid
               AND pit.to_matchday_id IS NULL
               AND t.season_id = :season_id
             LIMIT 1"
        );
        $q->execute([':pid' => $playerId, ':season_id' => $activeSeasonId]);
        return (bool) $q->fetchColumn();
    }

    public function isPositionFull(string $teamId, string $playerId): bool
    {
        $tq = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
        $tq->execute([':id' => $teamId]);
        $seasonId = $tq->fetchColumn();
        if (!$seasonId) return false;

        // pis auf die Zeile der AKTUELLEN Division des Spielers eingeschränkt (Fragment A).
        $pq = $this->con->prepare(
            "SELECT position FROM player_in_season pis
             WHERE pis.player_id = :pid AND pis.season_id = :sid
               AND pis.division_id = COALESCE(
                     (SELECT cis.division_id
                      FROM player_in_club pic
                      JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
                      WHERE pic.player_id = pis.player_id AND pic.to_date IS NULL
                      LIMIT 1),
                     pis.division_id)
             LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $position = $pq->fetchColumn();
        if (!$position || !isset(self::SQUAD_MAX[$position])) return false;

        $aq = $this->con_league->prepare(
            "SELECT player_id FROM player_in_team WHERE team_id = :tid AND to_matchday_id IS NULL"
        );
        $aq->execute([':tid' => $teamId]);
        $activeIds = $aq->fetchAll(PDO::FETCH_COLUMN);
        if (empty($activeIds)) return false;

        // Fragment A, Bulk-Form — jeder Kaderspieler auf seine eigene aktuelle Division eingeschränkt.
        $ph = implode(',', array_fill(0, count($activeIds), '?'));
        $cq = $this->con->prepare(
            "SELECT COUNT(*) FROM player_in_season pis
             LEFT JOIN player_in_club pic_cur ON pic_cur.player_id = pis.player_id AND pic_cur.to_date IS NULL
             LEFT JOIN club_in_season cis_cur ON cis_cur.club_id = pic_cur.club_id AND cis_cur.season_id = pis.season_id
             WHERE pis.player_id IN ($ph) AND pis.season_id = ? AND pis.position = ?
               AND (cis_cur.division_id IS NULL OR pis.division_id = cis_cur.division_id OR NOT EXISTS (SELECT 1 FROM player_in_season pis_chk WHERE pis_chk.player_id = pis.player_id AND pis_chk.season_id = pis.season_id AND pis_chk.division_id = cis_cur.division_id))"
        );
        $cq->execute(array_merge($activeIds, [$seasonId, $position]));
        $count = (int) $cq->fetchColumn();

        return $count >= self::SQUAD_MAX[$position];
    }

    /**
     * Grundpreis (Marktwert ohne Punkte-Bonus) des Spielers in der Saison, auf die Zeile seiner aktuellen
     * Division eingeschränkt (Fragment A). null = Mondpreis (noch kein Marktwert) oder keine Saison-Zeile —
     * dann ist der Spieler nicht kaufbar.
     */
    public function getPlayerBasePrice(string $playerId, string $seasonId): ?int
    {
        $pq = $this->con->prepare(
            "SELECT pis.price FROM player_in_season pis
             WHERE pis.player_id = :pid AND pis.season_id = :sid
               AND pis.division_id = COALESCE(
                     (SELECT cis.division_id
                      FROM player_in_club pic
                      JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
                      WHERE pic.player_id = pis.player_id AND pic.to_date IS NULL
                      LIMIT 1),
                     pis.division_id)
             LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $price = $pq->fetchColumn();
        return $price === false || $price === null ? null : (int) round((float) $price);
    }
}
