<?php

trait BuyTrait
{
    private const SQUAD_MAX = [
        'GOALKEEPER' => 2,
        'DEFENDER'   => 6,
        'MIDFIELDER' => 6,
        'FORWARD'    => 4,
    ];

    public function isPlayerAlreadyInAnyTeam(string $playerId): bool
    {
        $q = $this->con_league->prepare(
            "SELECT id FROM player_in_team WHERE player_id = :pid AND to_matchday_id IS NULL LIMIT 1"
        );
        $q->execute([':pid' => $playerId]);
        return (bool) $q->fetchColumn();
    }

    public function isPositionFull(string $teamId, string $playerId): bool
    {
        $tq = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
        $tq->execute([':id' => $teamId]);
        $seasonId = $tq->fetchColumn();
        if (!$seasonId) return false;

        $pq = $this->con->prepare(
            "SELECT position FROM player_in_season WHERE player_id = :pid AND season_id = :sid LIMIT 1"
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

        $ph = implode(',', array_fill(0, count($activeIds), '?'));
        $cq = $this->con->prepare(
            "SELECT COUNT(*) FROM player_in_season
             WHERE player_id IN ($ph) AND season_id = ? AND position = ?"
        );
        $cq->execute(array_merge($activeIds, [$seasonId, $position]));
        $count = (int) $cq->fetchColumn();

        return $count >= self::SQUAD_MAX[$position];
    }

    public function buyPlayer(string $teamId, string $playerId, string $windowId): array
    {
        $wq = $this->con->prepare(
            "SELECT tw.matchday_id, m.season_id
             FROM transferwindow tw
             JOIN matchday m ON m.id = tw.matchday_id
             WHERE tw.id = :id LIMIT 1"
        );
        $wq->execute([':id' => $windowId]);
        $window     = $wq->fetch(PDO::FETCH_ASSOC);
        $matchdayId = $window['matchday_id'];
        $seasonId   = $window['season_id'];

        $pq = $this->con->prepare(
            "SELECT COALESCE(pis.price, 0) AS price, p.displayname
             FROM player_in_season pis
             JOIN player p ON p.id = pis.player_id
             WHERE pis.player_id = :pid AND pis.season_id = :sid LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $ps          = $pq->fetch(PDO::FETCH_ASSOC);
        $price       = (int) round((float) $ps['price']);
        $displayname = $ps['displayname'];

        $iq = $this->con_league->prepare(
            "INSERT INTO player_in_team (team_id, player_id, from_matchday_id)
             VALUES (:tid, :pid, :mid)"
        );
        $iq->execute([':tid' => $teamId, ':pid' => $playerId, ':mid' => $matchdayId]);

        $tq = $this->con_league->prepare(
            "INSERT INTO transaction (team_id, amount, reason, matchday_id)
             VALUES (:tid, :amount, :reason, :mid)"
        );
        $tq->execute([
            ':tid'    => $teamId,
            ':amount' => -$price,
            ':reason' => "Spielerkauf: $displayname",
            ':mid'    => $matchdayId,
        ]);

        return ['price' => $price];
    }
}
