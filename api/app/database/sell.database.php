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

        $sellPrice = (int) round($basePrice + $seasonPoints * $this->getDivisionConfig()['points_bonus']);

        // 4. INSERT sell
        $sellId = $this->con_league->query("SELECT UUID()")->fetchColumn();
        $sq = $this->con_league->prepare(
            "INSERT INTO sell (id, player_id, team_id, transferwindow_id, price)
             VALUES (:id, :pid, :tid, :wid, :price)"
        );
        $sq->execute([':id' => $sellId, ':pid' => $playerId, ':tid' => $teamId, ':wid' => $windowId, ':price' => $sellPrice]);

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

        // 7. Cleanup team_lineup: remove the sold player's entries for every not-yet-completed
        // matchday, not just the one tied to this transfer window — a row for a later matchday
        // (e.g. carried over by ensureLineupEntriesForTeam()) would otherwise stay nominated and
        // could still get scored if that matchday completes before anyone happens to open the
        // lineup page (the only place that would lazily clean it up otherwise, see
        // getTeamLineup()'s stale-player cleanup, kept as a secondary safety net).
        $lq = $this->con_league->prepare(
            "SELECT id, matchday_id, nominated FROM team_lineup
             WHERE team_id = :tid AND player_id = :pid"
        );
        $lq->execute([':tid' => $teamId, ':pid' => $playerId]);
        $lineupEntries = $lq->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($lineupEntries)) {
            $mdIds = array_unique(array_column($lineupEntries, 'matchday_id'));
            $ph2   = implode(',', array_fill(0, count($mdIds), '?'));
            $cq    = $this->con->prepare("SELECT id FROM matchday WHERE id IN ($ph2) AND completed = 0");
            $cq->execute(array_values($mdIds));
            $openMatchdayIds = array_flip($cq->fetchAll(PDO::FETCH_COLUMN));

            $toDelete           = [];
            $nominatedMatchdays = [];
            foreach ($lineupEntries as $e) {
                if (!isset($openMatchdayIds[$e['matchday_id']])) continue; // completed → historical, never touch
                $toDelete[] = $e['id'];
                if ($e['nominated']) $nominatedMatchdays[] = $e['matchday_id'];
            }

            if (!empty($toDelete)) {
                $ph3 = implode(',', array_fill(0, count($toDelete), '?'));
                $this->con_league->prepare("DELETE FROM team_lineup WHERE id IN ($ph3)")->execute($toDelete);
            }
            foreach (array_unique($nominatedMatchdays) as $mdId) {
                // Nominated → move remaining nominated players of that matchday to bench
                $bq = $this->con_league->prepare(
                    "UPDATE team_lineup SET nominated = 0, position_index = NULL
                     WHERE team_id = :tid AND matchday_id = :mid AND nominated = 1"
                );
                $bq->execute([':tid' => $teamId, ':mid' => $mdId]);
            }
        }

        $this->notifyWatchersPlayerSold($playerId, $teamId, $displayname);

        return ['sell_id' => $sellId, 'price' => $sellPrice];
    }
}
