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

        $nameMap = [];
        if (!empty($playerIds)) {
            $ph  = implode(',', array_fill(0, count($playerIds), '?'));
            $pq  = $this->con->prepare("SELECT id, displayname FROM player WHERE id IN ($ph)");
            $pq->execute($playerIds);
            foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $nameMap[$p['id']] = $p['displayname'];
            }
        }

        $offers = array_map(fn($r) => array_merge($r, [
            'displayname' => $nameMap[$r['player_id']] ?? null,
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

        // 3. Price snapshot
        $pq = $this->con->prepare(
            "SELECT COALESCE(price, 0) FROM player_in_season
             WHERE player_id = :pid AND season_id = :sid LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $priceSnapshot = (int) $pq->fetchColumn();

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
}
