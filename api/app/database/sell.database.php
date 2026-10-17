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

        // 2. Base price + displayname — pis auf die Zeile der AKTUELLEN Division eingeschränkt
        // (Fragment A). Die Saisonpunkte-Summe weiter unten bleibt bewusst divisionsübergreifend
        // (Verkaufspreis soll die volle Saisonleistung berücksichtigen, auch über einen Wechsel
        // hinweg) — nur der Grundpreis selbst muss zur aktuellen Division passen.
        $pq = $this->con->prepare(
            "SELECT COALESCE(pis.price, 0) AS price, p.displayname
             FROM player_in_season pis
             JOIN player p ON p.id = pis.player_id
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

        // 7. Cleanup team_lineup (siehe removePlayerFromOpenLineups())
        $this->removePlayerFromOpenLineups($teamId, $playerId);

        // 8. Offene Direktangebote (/player_offer) auf diesen Spieler sind hinfällig — der Spieler
        // gehört dem Verkäufer nicht mehr. Fehler dürfen den bereits gebuchten Verkauf nicht kippen.
        try {
            foreach ($this->voidPendingPlayerOffers($playerId) as $v) {
                $this->notifyTeamManager($v['buyer_team_id'], 'Angebot hinfällig', "$displayname wurde an den Markt verkauft — dein Angebot ist hinfällig.");
            }
        } catch (\Throwable $e) {
            error_log('sellPlayer void player offers: ' . $e->getMessage());
        }

        $this->notifyWatchersPlayerSold($playerId, $teamId, $displayname);

        return ['sell_id' => $sellId, 'price' => $sellPrice];
    }

    /**
     * Cleanup team_lineup: remove ONLY the given player's own entries of this team, for every
     * not-yet-completed matchday, not just the one tied to the transfer window — a row for a later
     * matchday (e.g. carried over by ensureLineupEntriesForTeam()) would otherwise stay nominated and
     * could still get scored if that matchday completes before anyone happens to open the lineup page
     * (the only place that would lazily clean it up otherwise, see getTeamLineup()'s stale-player
     * cleanup, kept as a secondary safety net). The other nominated players stay as they are; if the
     * sold player was nominated, the gap is filled automatically from the bench (fillLineupGap()):
     * the cheapest bench player of the same Mannschaftsteil moves into the same slot, so the lineup
     * stays complete — fewer than 11 nominated would make it invalid (0 points, see
     * finalizeMatchday()). Without a fitting bench player the gap stays. Used by sellPlayer() and by
     * acceptPlayerOffer() (direct offers, incl. offered players of a swap).
     */
    public function removePlayerFromOpenLineups(string $teamId, string $playerId): void
    {
        $lq = $this->con_league->prepare(
            "SELECT id, matchday_id, nominated, position_index FROM team_lineup
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

            $toDelete = [];
            $gaps     = [];
            foreach ($lineupEntries as $e) {
                if (!isset($openMatchdayIds[$e['matchday_id']])) continue; // completed → historical, never touch
                $toDelete[] = $e['id'];
                if ((int) $e['nominated'] === 1) $gaps[] = $e;
            }

            if (!empty($toDelete)) {
                $ph3 = implode(',', array_fill(0, count($toDelete), '?'));
                $this->con_league->prepare("DELETE FROM team_lineup WHERE id IN ($ph3)")->execute($toDelete);
            }

            // war er aufgestellt: Lücke aus der Bank füllen (eigener try — darf den Verkauf nie kippen)
            foreach ($gaps as $gap) {
                try {
                    $this->fillLineupGap($teamId, $gap['matchday_id'], $playerId, $gap['position_index']);
                } catch (\Throwable $ex) {
                    error_log('fillLineupGap: ' . $ex->getMessage());
                }
            }
        }
    }

    /**
     * Füllt den Platz eines verkauften, aufgestellten Spielers aus der Bank: unter den nicht aufgestellten Spielern
     * des Teams an diesem Spieltag derjenige derselben Position (Mannschaftsteil) mit dem niedrigsten Marktwert
     * (player_in_season der Saison des Spieltags, die Zeile seiner Division bevorzugt). Er übernimmt den Platz
     * (position_index) des Verkauften. Gibt es keinen passenden Bankspieler, bleibt die Lücke.
     * Gibt die player_id des Nachrückers zurück, sonst null.
     */
    private function fillLineupGap(string $teamId, string $matchdayId, string $soldPlayerId, $positionIndex): ?string
    {
        $mq = $this->con->prepare("SELECT season_id, division_id FROM matchday WHERE id = ?");
        $mq->execute([$matchdayId]);
        $md = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$md) return null;

        $bq = $this->con_league->prepare(
            "SELECT player_id FROM team_lineup WHERE team_id = ? AND matchday_id = ? AND nominated = 0"
        );
        $bq->execute([$teamId, $matchdayId]);
        $benchIds = $bq->fetchAll(PDO::FETCH_COLUMN);
        if (empty($benchIds)) return null;

        // Position + Marktwert je Spieler aus seiner Saison-Zeile, die Zeile der Division des Spieltags bevorzugt
        $ids = array_merge([$soldPlayerId], $benchIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $pq  = $this->con->prepare(
            "SELECT player_id, position, price FROM player_in_season
             WHERE season_id = ? AND player_id IN ($ph)
             ORDER BY (division_id = ?) DESC"
        );
        $pq->execute(array_merge([$md['season_id']], $ids, [$md['division_id']]));
        $info = [];
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $info[$r['player_id']] ??= $r;
        }

        $position = $info[$soldPlayerId]['position'] ?? null;
        if ($position === null) return null;

        $best = null;
        foreach ($benchIds as $id) {
            $c = $info[$id] ?? null;
            if (!$c || $c['position'] !== $position) continue;
            if ($best === null || (float) $c['price'] < (float) $best['price']) $best = $c;
        }
        if ($best === null) return null;

        $this->con_league->prepare(
            "UPDATE team_lineup SET nominated = 1, position_index = ?
             WHERE team_id = ? AND matchday_id = ? AND player_id = ?"
        )->execute([$positionIndex, $teamId, $matchdayId, $best['player_id']]);
        return $best['player_id'];
    }
}
