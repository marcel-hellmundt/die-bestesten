<?php

trait OfferTrait
{
    public function getMyOffers(string $teamId): array
    {
        $q = $this->con_league->prepare(
            "SELECT id, player_id, transferwindow_id, offer_value, price_snapshot, status, created_at
             FROM offer WHERE team_id = :tid ORDER BY created_at DESC"
        );
        $q->execute([':tid' => $teamId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $pendingSum = 0;
        $playerIds  = [];
        foreach ($rows as $r) {
            $playerIds[] = $r['player_id'];
            if ($r['status'] === 'pending') {
                $pendingSum += (int) $r['offer_value'];
            }
        }

        $playerMap = [];
        if (!empty($playerIds)) {
            $activeSeasonId = $this->con->query(
                "SELECT id FROM season ORDER BY start_date DESC LIMIT 1"
            )->fetchColumn();

            $ph  = implode(',', array_fill(0, count($playerIds), '?'));
            $pq  = $this->con->prepare(
                "SELECT p.id, p.displayname, pis.photo_uploaded,
                        pic.club_id, c.logo_uploaded AS club_logo_uploaded
                 FROM player p
                 LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ?
                 LEFT JOIN player_in_club pic ON pic.player_id = p.id AND pic.to_date IS NULL
                 LEFT JOIN club c ON c.id = pic.club_id
                 WHERE p.id IN ($ph)"
            );
            $pq->execute(array_merge([$activeSeasonId], array_values($playerIds)));
            foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $playerMap[$p['id']] = [
                    'displayname'        => $p['displayname'],
                    'photo_uploaded'     => (bool) $p['photo_uploaded'],
                    'club_id'            => $p['club_id'],
                    'club_logo_uploaded' => (bool) $p['club_logo_uploaded'],
                    'season_id'          => $activeSeasonId,
                ];
            }
        }

        // Fetch losing bidder team info for settled offers (one batch query)
        $settledPairs  = [];
        foreach ($rows as $r) {
            if (in_array($r['status'], ['success', 'lost'])) {
                $key = $r['player_id'] . '|' . $r['transferwindow_id'];
                $settledPairs[$key] = ['player_id' => $r['player_id'], 'transferwindow_id' => $r['transferwindow_id']];
            }
        }

        $losersMap = [];
        if (!empty($settledPairs)) {
            $conditions = [];
            $params     = [];
            foreach (array_values($settledPairs) as $pair) {
                $conditions[] = "(o.player_id = ? AND o.transferwindow_id = ?)";
                $params[]     = $pair['player_id'];
                $params[]     = $pair['transferwindow_id'];
            }
            $lq = $this->con_league->prepare(
                "SELECT o.id AS offer_id, o.player_id, o.transferwindow_id,
                        o.team_id, o.status, t.color AS team_color, t.season_id AS team_season_id
                 FROM offer o JOIN team t ON t.id = o.team_id
                 WHERE o.status IN ('lost', 'success') AND (" . implode(' OR ', $conditions) . ")"
            );
            $lq->execute($params);

            $pairLoserMap = [];
            foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $key = $l['player_id'] . '|' . $l['transferwindow_id'];
                $pairLoserMap[$key][$l['offer_id']] = [
                    'offer_id'       => $l['offer_id'],
                    'team_id'        => $l['team_id'],
                    'team_color'     => $l['team_color'],
                    'team_season_id' => $l['team_season_id'],
                    'is_winner'      => $l['status'] === 'success',
                ];
            }

            foreach ($rows as $r) {
                if (in_array($r['status'], ['success', 'lost'])) {
                    $key        = $r['player_id'] . '|' . $r['transferwindow_id'];
                    $allLosers  = array_values($pairLoserMap[$key] ?? []);
                    $losersMap[$r['id']] = array_values(array_map(
                        fn($l) => ['team_id' => $l['team_id'], 'team_color' => $l['team_color'], 'team_season_id' => $l['team_season_id'], 'is_winner' => $l['is_winner']],
                        array_filter($allLosers, fn($l) => $l['offer_id'] !== $r['id'])
                    ));
                }
            }
        }

        $offers = array_map(fn($r) => array_merge($r, [
            'displayname'        => $playerMap[$r['player_id']]['displayname']        ?? null,
            'photo_uploaded'     => $playerMap[$r['player_id']]['photo_uploaded']     ?? false,
            'club_id'            => $playerMap[$r['player_id']]['club_id']            ?? null,
            'club_logo_uploaded' => $playerMap[$r['player_id']]['club_logo_uploaded'] ?? false,
            'season_id'          => $playerMap[$r['player_id']]['season_id']          ?? null,
            'losers'             => $losersMap[$r['id']]                              ?? [],
        ]), $rows);

        return ['offers' => $offers, 'pending_sum' => $pendingSum];
    }

    public function submitOffer(string $teamId, string $playerId,
                                string $windowId, int $offerValue): array
    {
        // 1. Validate window open + get season_id
        $wq = $this->con->prepare(
            "SELECT tw.matchday_id, m.season_id,
                    tw.start_date, tw.end_date
             FROM transferwindow tw JOIN matchday m ON m.id = tw.matchday_id
             WHERE tw.id = :id LIMIT 1"
        );
        $wq->execute([':id' => $windowId]);
        $window = $wq->fetch(PDO::FETCH_ASSOC);

        if (!$window || !(strtotime($window['start_date']) <= time() && time() < strtotime($window['end_date']))) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'Transferwindow not open']);
            exit;
        }

        $seasonId = $window['season_id'];

        // 2. Validate player is free (not in any active team this season)
        $sQ = $this->con->query("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
        $activeSeasonId = $sQ->fetchColumn();
        $aq = $this->con_league->prepare(
            "SELECT pit.id FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.player_id = :pid AND pit.to_matchday_id IS NULL AND t.season_id = :sid LIMIT 1"
        );
        $aq->execute([':pid' => $playerId, ':sid' => $activeSeasonId]);
        if ($aq->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['status' => false, 'message' => 'Player already in a team']);
            exit;
        }

        // 3. Price snapshot = base price + points_in_season * 20000
        $pq = $this->con->prepare(
            "SELECT COALESCE(price, 0) FROM player_in_season
             WHERE player_id = :pid AND season_id = :sid LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $basePrice = (int) $pq->fetchColumn();

        $ptq = $this->con->prepare(
            "SELECT COALESCE(SUM(pr.points), 0) FROM player_rating pr
             JOIN matchday m ON m.id = pr.matchday_id
             WHERE pr.player_id = :pid AND m.season_id = :sid"
        );
        $ptq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $pointsInSeason = (int) $ptq->fetchColumn();

        $priceSnapshot = $basePrice + $pointsInSeason * 20000;

        // 4. Validate offer >= price
        if ($offerValue < $priceSnapshot) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'Offer below market value']);
            exit;
        }

        // 5. Budget
        $bq = $this->con_league->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transaction WHERE team_id = :tid"
        );
        $bq->execute([':tid' => $teamId]);
        $budget = (int) $bq->fetchColumn();

        // 6. Pending sum
        $psq = $this->con_league->prepare(
            "SELECT COALESCE(SUM(offer_value), 0) FROM offer
             WHERE team_id = :tid AND status = 'pending'"
        );
        $psq->execute([':tid' => $teamId]);
        $pendingSum = (int) $psq->fetchColumn();

        // 7. Validate budget
        if ($offerValue > ($budget - $pendingSum)) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'Insufficient budget']);
            exit;
        }

        // 8. Insert offer
        $id = bin2hex(random_bytes(16));
        $id = sprintf('%s-%s-%s-%s-%s',
            substr($id, 0, 8), substr($id, 8, 4),
            substr($id, 12, 4), substr($id, 16, 4),
            substr($id, 20, 12)
        );

        $iq = $this->con_league->prepare(
            "INSERT INTO offer (id, player_id, team_id, transferwindow_id, offer_value, price_snapshot, status)
             VALUES (:id, :pid, :tid, :wid, :val, :snap, 'pending')"
        );
        $iq->execute([
            ':id'   => $id,
            ':pid'  => $playerId,
            ':tid'  => $teamId,
            ':wid'  => $windowId,
            ':val'  => $offerValue,
            ':snap' => $priceSnapshot,
        ]);

        return ['offer_id' => $id];
    }

    public function cancelOffer(string $offerId, string $teamId): bool
    {
        $q = $this->con_league->prepare(
            "UPDATE offer SET status = 'cancelled'
             WHERE id = :oid AND team_id = :tid AND status = 'pending'"
        );
        $q->execute([':oid' => $offerId, ':tid' => $teamId]);
        return $q->rowCount() > 0;
    }

    public function updateOfferValue(string $offerId, string $teamId, int $newValue): array
    {
        $oq = $this->con_league->prepare(
            "SELECT price_snapshot FROM offer WHERE id = :id AND team_id = :tid AND status = 'pending' LIMIT 1"
        );
        $oq->execute([':id' => $offerId, ':tid' => $teamId]);
        $offer = $oq->fetch(PDO::FETCH_ASSOC);
        if (!$offer) return ['error' => 'not_found'];

        if ($newValue < (int) $offer['price_snapshot']) return ['error' => 'below_market'];

        $bq = $this->con_league->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transaction WHERE team_id = :tid"
        );
        $bq->execute([':tid' => $teamId]);
        $budget = (int) $bq->fetchColumn();

        $psq = $this->con_league->prepare(
            "SELECT COALESCE(SUM(offer_value), 0) FROM offer
             WHERE team_id = :tid AND status = 'pending' AND id != :oid"
        );
        $psq->execute([':tid' => $teamId, ':oid' => $offerId]);
        $otherPending = (int) $psq->fetchColumn();

        if ($newValue > ($budget - $otherPending)) return ['error' => 'budget_exceeded'];

        $uq = $this->con_league->prepare(
            "UPDATE offer SET offer_value = :val WHERE id = :id AND team_id = :tid AND status = 'pending'"
        );
        $uq->execute([':val' => $newValue, ':id' => $offerId, ':tid' => $teamId]);
        return ['success' => true];
    }

    public function settleWindow(string $windowId): void
    {
        $wq = $this->con->prepare(
            "SELECT tw.matchday_id, m.season_id, tw.end_date
             FROM transferwindow tw JOIN matchday m ON m.id = tw.matchday_id
             WHERE tw.id = :id LIMIT 1"
        );
        $wq->execute([':id' => $windowId]);
        $window = $wq->fetch(PDO::FETCH_ASSOC);
        if (!$window || strtotime($window['end_date']) >= time()) return;

        $chk = $this->con_league->prepare(
            "SELECT COUNT(*) FROM offer WHERE transferwindow_id = :wid AND status = 'pending'"
        );
        $chk->execute([':wid' => $windowId]);
        if ((int) $chk->fetchColumn() === 0) return;

        $matchdayId = $window['matchday_id'];

        $pq = $this->con_league->prepare(
            "SELECT DISTINCT player_id FROM offer WHERE transferwindow_id = :wid AND status = 'pending'"
        );
        $pq->execute([':wid' => $windowId]);
        $playerIds = $pq->fetchAll(PDO::FETCH_COLUMN);

        $ph  = implode(',', array_fill(0, count($playerIds), '?'));
        $dnq = $this->con->prepare("SELECT id, displayname FROM player WHERE id IN ($ph)");
        $dnq->execute($playerIds);
        $displaynames = [];
        foreach ($dnq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $displaynames[$row['id']] = $row['displayname'];
        }

        $this->con_league->beginTransaction();
        try {
            foreach ($playerIds as $playerId) {
                $bq = $this->con_league->prepare(
                    "SELECT id, team_id, offer_value FROM offer
                     WHERE transferwindow_id = :wid AND player_id = :pid AND status = 'pending'
                     ORDER BY offer_value DESC, created_at ASC"
                );
                $bq->execute([':wid' => $windowId, ':pid' => $playerId]);
                $bids = $bq->fetchAll(PDO::FETCH_ASSOC);

                $winnerId     = null;
                $winnerTeamId = null;
                $winnerValue  = null;

                foreach ($bids as $bid) {
                    if ($this->isPlayerAlreadyInAnyTeam($playerId)) break;
                    if ($this->isPositionFull($bid['team_id'], $playerId)) continue;
                    $winnerId     = $bid['id'];
                    $winnerTeamId = $bid['team_id'];
                    $winnerValue  = (int) $bid['offer_value'];
                    break;
                }

                $allBidIds = array_column($bids, 'id');

                if ($winnerId !== null) {
                    $this->con_league->prepare(
                        "UPDATE offer SET status = 'success' WHERE id = :id"
                    )->execute([':id' => $winnerId]);

                    $this->con_league->prepare(
                        "INSERT INTO player_in_team (team_id, player_id, from_matchday_id, offer_id)
                         VALUES (:tid, :pid, :mid, :oid)"
                    )->execute([
                        ':tid' => $winnerTeamId,
                        ':pid' => $playerId,
                        ':mid' => $matchdayId,
                        ':oid' => $winnerId,
                    ]);

                    $displayname = $displaynames[$playerId] ?? 'Spieler';
                    $this->con_league->prepare(
                        "INSERT INTO transaction (team_id, amount, reason, matchday_id)
                         VALUES (:tid, :amount, :reason, :mid)"
                    )->execute([
                        ':tid'    => $winnerTeamId,
                        ':amount' => -$winnerValue,
                        ':reason' => "Spielerkauf (Gebot): $displayname",
                        ':mid'    => $matchdayId,
                    ]);

                    $loserIds = array_values(array_filter($allBidIds, fn($id) => $id !== $winnerId));
                } else {
                    $loserIds = $allBidIds;
                }

                if (!empty($loserIds)) {
                    $lph = implode(',', array_fill(0, count($loserIds), '?'));
                    $this->con_league->prepare(
                        "UPDATE offer SET status = 'lost' WHERE id IN ($lph)"
                    )->execute($loserIds);
                }
            }

            $this->con_league->commit();
        } catch (\Exception $e) {
            $this->con_league->rollBack();
            throw $e;
        }
    }

    public function getWindowOffers(string $windowId): array
    {
        $wq = $this->con->prepare(
            "SELECT id, matchday_id, start_date, end_date FROM transferwindow WHERE id = :id LIMIT 1"
        );
        $wq->execute([':id' => $windowId]);
        $window = $wq->fetch(PDO::FETCH_ASSOC);

        $oq = $this->con_league->prepare(
            "SELECT o.id, o.player_id, o.team_id, o.offer_value, o.price_snapshot, o.status, o.created_at
             FROM offer o
             WHERE o.transferwindow_id = :wid AND o.status != 'cancelled'
             ORDER BY o.player_id, o.offer_value DESC, o.created_at ASC"
        );
        $oq->execute([':wid' => $windowId]);
        $rows = $oq->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return ['window' => $window, 'offers' => []];
        }

        $teamIds = array_unique(array_column($rows, 'team_id'));
        $tph     = implode(',', array_fill(0, count($teamIds), '?'));
        $tq      = $this->con_league->prepare("SELECT id, team_name, color, season_id FROM team WHERE id IN ($tph)");
        $tq->execute(array_values($teamIds));
        $teamMap = [];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $teamMap[$t['id']] = ['team_name' => $t['team_name'], 'color' => $t['color'], 'season_id' => $t['season_id']];
        }

        $playerIds      = array_unique(array_column($rows, 'player_id'));
        $pph            = implode(',', array_fill(0, count($playerIds), '?'));
        $activeSeasonId = $this->con->query(
            "SELECT id FROM season ORDER BY start_date DESC LIMIT 1"
        )->fetchColumn();

        $pq = $this->con->prepare(
            "SELECT p.id, p.displayname, pis.position, pis.photo_uploaded,
                    pic.club_id, c.logo_uploaded AS club_logo_uploaded
             FROM player p
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ?
             LEFT JOIN player_in_club pic ON pic.player_id = p.id AND pic.to_date IS NULL
             LEFT JOIN club c ON c.id = pic.club_id
             WHERE p.id IN ($pph)"
        );
        $pq->execute(array_merge([$activeSeasonId], array_values($playerIds)));
        $playerMap = [];
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $playerMap[$p['id']] = [
                'displayname'        => $p['displayname'],
                'position'           => $p['position'],
                'photo_uploaded'     => (bool) $p['photo_uploaded'],
                'club_id'            => $p['club_id'],
                'club_logo_uploaded' => (bool) $p['club_logo_uploaded'],
            ];
        }

        $grouped = [];
        foreach ($rows as $r) {
            $pid = $r['player_id'];
            if (!isset($grouped[$pid])) {
                $pm = $playerMap[$pid] ?? [];
                $grouped[$pid] = [
                    'player_id'          => $pid,
                    'season_id'          => $activeSeasonId,
                    'displayname'        => $pm['displayname']        ?? null,
                    'position'           => $pm['position']           ?? null,
                    'photo_uploaded'     => $pm['photo_uploaded']     ?? false,
                    'club_id'            => $pm['club_id']            ?? null,
                    'club_logo_uploaded' => $pm['club_logo_uploaded'] ?? false,
                    'bids'               => [],
                ];
            }
            $tm = $teamMap[$r['team_id']] ?? [];
            $grouped[$pid]['bids'][] = [
                'id'             => $r['id'],
                'team_id'        => $r['team_id'],
                'team_name'      => $tm['team_name']  ?? null,
                'team_color'     => $tm['color']      ?? null,
                'team_season_id' => $tm['season_id']  ?? null,
                'offer_value'    => (int) $r['offer_value'],
                'price_snapshot' => $r['price_snapshot'] !== null ? (int) $r['price_snapshot'] : null,
                'status'         => $r['status'],
                'created_at'     => $r['created_at'],
            ];
        }

        return ['window' => $window, 'offers' => array_values($grouped)];
    }
}
