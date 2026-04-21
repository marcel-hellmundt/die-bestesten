<?php

trait SellTrait
{
    public function isTransferwindowOpen(string $windowId): bool
    {
        $q = $this->con->prepare(
            "SELECT id FROM transferwindow
             WHERE id = :id AND start_date <= NOW() AND end_date > NOW() LIMIT 1"
        );
        $q->execute([':id' => $windowId]);
        return (bool) $q->fetchColumn();
    }

    public function isPlayerActiveInTeam(string $teamId, string $playerId): bool
    {
        $q = $this->con_league->prepare(
            "SELECT id FROM player_in_team
             WHERE team_id = :tid AND player_id = :pid AND to_matchday_id IS NULL LIMIT 1"
        );
        $q->execute([':tid' => $teamId, ':pid' => $playerId]);
        return (bool) $q->fetchColumn();
    }

    public function sellPlayer(string $teamId, string $playerId, string $windowId): array
    {
        // 1. transferwindow → matchday_id + season_id
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

        // 2. Base price + displayname
        $pq = $this->con->prepare(
            "SELECT COALESCE(pis.price, 0) AS price, p.displayname
             FROM player_in_season pis
             JOIN player p ON p.id = pis.player_id
             WHERE pis.player_id = :pid AND pis.season_id = :sid LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $ps          = $pq->fetch(PDO::FETCH_ASSOC);
        $basePrice   = (float) $ps['price'];
        $displayname = $ps['displayname'];

        // 3. Season points
        $ptq = $this->con->prepare(
            "SELECT COALESCE(SUM(pr.points), 0)
             FROM player_rating pr
             JOIN matchday m ON m.id = pr.matchday_id
             WHERE pr.player_id = :pid AND m.season_id = :sid"
        );
        $ptq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $seasonPoints = (int) $ptq->fetchColumn();

        $sellPrice = (int) round($basePrice + $seasonPoints * 20000);

        // 4. INSERT sell
        $sq = $this->con_league->prepare(
            "INSERT INTO sell (player_id, team_id, transferwindow_id, price)
             VALUES (:pid, :tid, :wid, :price)"
        );
        $sq->execute([':pid' => $playerId, ':tid' => $teamId, ':wid' => $windowId, ':price' => $sellPrice]);
        $sellId = $this->con_league->lastInsertId();

        // 5. INSERT transaction
        $tq = $this->con_league->prepare(
            "INSERT INTO transaction (team_id, amount, reason, matchday_id)
             VALUES (:tid, :amount, :reason, :mid)"
        );
        $tq->execute([
            ':tid'    => $teamId,
            ':amount' => $sellPrice,
            ':reason' => "Spielerverkauf: $displayname",
            ':mid'    => $matchdayId,
        ]);

        // 6. Close player_in_team
        $uq = $this->con_league->prepare(
            "UPDATE player_in_team SET to_matchday_id = :mid, sell_id = :sid
             WHERE team_id = :tid AND player_id = :pid AND to_matchday_id IS NULL"
        );
        $uq->execute([':mid' => $matchdayId, ':sid' => $sellId, ':tid' => $teamId, ':pid' => $playerId]);

        // 7. Cleanup team_lineup for this matchday
        $lq = $this->con_league->prepare(
            "SELECT id, nominated FROM team_lineup
             WHERE team_id = :tid AND player_id = :pid AND matchday_id = :mid LIMIT 1"
        );
        $lq->execute([':tid' => $teamId, ':pid' => $playerId, ':mid' => $matchdayId]);
        $lineupEntry = $lq->fetch(PDO::FETCH_ASSOC);

        if ($lineupEntry) {
            // Always remove the sold player's entry
            $dq = $this->con_league->prepare("DELETE FROM team_lineup WHERE id = :id");
            $dq->execute([':id' => $lineupEntry['id']]);

            if ($lineupEntry['nominated']) {
                // Nominated → move remaining nominated players to bench
                $bq = $this->con_league->prepare(
                    "UPDATE team_lineup SET nominated = 0, position_index = NULL
                     WHERE team_id = :tid AND matchday_id = :mid AND nominated = 1"
                );
                $bq->execute([':tid' => $teamId, ':mid' => $matchdayId]);
            }
        }

        return ['sell_id' => $sellId, 'price' => $sellPrice];
    }
}
