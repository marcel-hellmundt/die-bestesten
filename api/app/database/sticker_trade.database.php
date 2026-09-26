<?php

/**
 * "Die Klebrigsten" — Tauschen unter Managern: from_manager bietet to_manager eigene Doppelte gegen dessen
 * Doppelte an; to_manager nimmt an (Karten wechseln sofort den Besitzer) oder lehnt ab, from_manager kann
 * zurückziehen. Getauscht werden nur Doppelte — das letzte Exemplar bleibt immer im Album. Ist beim Annehmen
 * ein Sticker kein Doppelter mehr, wird das Angebot hinfällig (void); offene Angebote werden beim Auflisten
 * genauso geprüft. Keine Gegenangebote, keine Reservierung.
 */
trait StickerTradeTrait
{
    private const TRADE_MAX_ITEMS   = 10;  // Sticker je Seite
    private const TRADE_MAX_PENDING = 20;  // offene Angebote je Manager

    /** Tabellen vorhanden? (Migration migrate_sticker_trade.sql) */
    private function stickerTradeReady(): bool
    {
        try {
            $this->con->query("SELECT 1 FROM sticker_trade LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Anzahl je Sticker (sticker_id → count) eines Managers in der Saison. */
    private function stickerCounts(string $managerId, string $seasonId): array
    {
        $q = $this->con->prepare(
            "SELECT p.sticker_id, COUNT(*) FROM sticker_pull p JOIN sticker s ON s.id = p.sticker_id
             WHERE p.manager_id = ? AND s.season_id = ? GROUP BY p.sticker_id"
        );
        $q->execute([$managerId, $seasonId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Offene eingehende Angebote (Badge in /sticker/me). */
    public function countIncomingStickerTrades(string $managerId, string $seasonId): int
    {
        if (!$this->stickerTradeReady()) return 0;
        $q = $this->con->prepare("SELECT COUNT(*) FROM sticker_trade WHERE to_manager_id = ? AND season_id = ? AND status = 'pending'");
        $q->execute([$managerId, $seasonId]);
        return (int) $q->fetchColumn();
    }

    /**
     * POST /sticker/trade — Angebot anlegen. $give = eigene Doppelte, $get = Doppelte des anderen (sticker_keys).
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['id' => …].
     */
    public function createStickerTrade(string $fromId, string $toId, array $give, array $get): array
    {
        if (!$this->stickerTradeReady()) return ['error' => 409, 'message' => 'Tauschen ist noch nicht eingerichtet'];
        $give = array_values(array_unique(array_filter($give, 'is_string')));
        $get  = array_values(array_unique(array_filter($get, 'is_string')));
        if (!$give || !$get) return ['error' => 400, 'message' => 'Beide Seiten brauchen mindestens einen Sticker'];
        if (count($give) > self::TRADE_MAX_ITEMS || count($get) > self::TRADE_MAX_ITEMS) {
            return ['error' => 422, 'message' => 'Höchstens ' . self::TRADE_MAX_ITEMS . ' Sticker je Seite'];
        }
        if ($fromId === $toId) return ['error' => 422, 'message' => 'Mit dir selbst kannst du nicht tauschen'];
        if (!$this->isStickerEnabledForManager($fromId) || !$this->isStickerEnabledForManager($toId)) {
            return ['error' => 404, 'message' => 'Dieser Manager hat kein Sammelalbum'];
        }
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null || !$this->stickerAlbumReady($seasonId)) return ['error' => 409, 'message' => 'Album der Saison existiert noch nicht'];

        $pq = $this->con->prepare("SELECT COUNT(*) FROM sticker_trade WHERE from_manager_id = ? AND status = 'pending'");
        $pq->execute([$fromId]);
        if ((int) $pq->fetchColumn() >= self::TRADE_MAX_PENDING) {
            return ['error' => 409, 'message' => 'Du hast schon ' . self::TRADE_MAX_PENDING . ' offene Tauschangebote'];
        }

        // sticker_key → id
        $keys = array_merge($give, $get);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $sq = $this->con->prepare("SELECT sticker_key, id FROM sticker WHERE season_id = ? AND sticker_key IN ($in)");
        $sq->execute([$seasonId, ...$keys]);
        $ids = $sq->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($keys as $k) if (!isset($ids[$k])) return ['error' => 422, 'message' => 'Unbekannter Sticker'];

        $mine = $this->stickerCounts($fromId, $seasonId);
        $theirs = $this->stickerCounts($toId, $seasonId);
        foreach ($give as $k) if (($mine[$ids[$k]] ?? 0) < 2) return ['error' => 409, 'message' => 'Du kannst nur Doppelte tauschen'];
        foreach ($get as $k) if (($theirs[$ids[$k]] ?? 0) < 2) return ['error' => 409, 'message' => 'Der andere hat nicht alle Sticker doppelt'];

        $this->con->beginTransaction();
        try {
            $id = $this->con->query("SELECT UUID()")->fetchColumn();
            $this->con->prepare("INSERT INTO sticker_trade (id, season_id, from_manager_id, to_manager_id) VALUES (?, ?, ?, ?)")
                ->execute([$id, $seasonId, $fromId, $toId]);
            $ins = $this->con->prepare("INSERT INTO sticker_trade_item (trade_id, sticker_id, giver_id) VALUES (?, ?, ?)");
            foreach ($give as $k) $ins->execute([$id, $ids[$k], $fromId]);
            foreach ($get as $k) $ins->execute([$id, $ids[$k], $toId]);
            $this->con->commit();
        } catch (\Throwable $e) {
            $this->con->rollBack();
            throw $e;
        }

        $this->notifyStickerTrade($toId, $fromId, 'Neues Tauschangebot',
            count($give) . ' gegen ' . count($get) . ' Sticker – schau in der Tauschbörse der Klebrigsten vorbei.');
        return ['id' => $id];
    }

    /**
     * PATCH /sticker/trade/:id — Empfänger nimmt an (Karten wechseln sofort den Besitzer) oder lehnt ab.
     */
    public function respondStickerTrade(string $managerId, string $tradeId, string $action): array
    {
        if (!$this->stickerTradeReady()) return ['error' => 409, 'message' => 'Tauschen ist noch nicht eingerichtet'];
        $trade = $this->loadStickerTrade($tradeId);
        if (!$trade || $trade['to_manager_id'] !== $managerId) return ['error' => 404, 'message' => 'Tauschangebot nicht gefunden'];
        if ($trade['status'] !== 'pending') return ['error' => 409, 'message' => 'Tauschangebot ist nicht mehr offen'];

        if ($action === 'decline') {
            $this->setStickerTradeStatus($tradeId, 'declined');
            $this->notifyStickerTrade($trade['from_manager_id'], $managerId, 'Tauschangebot abgelehnt', null);
            return ['status' => 'declined'];
        }

        $this->con->beginTransaction();
        try {
            // Angebot sperren — zwei gleichzeitige Klicks dürfen nicht doppelt tauschen
            $lq = $this->con->prepare("SELECT status FROM sticker_trade WHERE id = ? FOR UPDATE");
            $lq->execute([$tradeId]);
            if ($lq->fetchColumn() !== 'pending') {
                $this->con->rollBack();
                return ['error' => 409, 'message' => 'Tauschangebot ist nicht mehr offen'];
            }
            $iq = $this->con->prepare("SELECT sticker_id, giver_id FROM sticker_trade_item WHERE trade_id = ?");
            $iq->execute([$tradeId]);
            $items = $iq->fetchAll(PDO::FETCH_ASSOC);

            $pulls = $this->con->prepare(
                "SELECT id FROM sticker_pull WHERE manager_id = ? AND sticker_id = ?
                 ORDER BY holo IS NOT NULL, holo = 'gold', created_at DESC FOR UPDATE"
            );
            $move = $this->con->prepare("UPDATE sticker_pull SET manager_id = ?, trade_id = ?, created_at = NOW() WHERE id = ?");
            foreach ($items as $it) {
                $pulls->execute([$it['giver_id'], $it['sticker_id']]);
                $ids = $pulls->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) < 2) {
                    // kein Doppelter mehr → Angebot hinfällig
                    $this->con->rollBack();
                    $this->setStickerTradeStatus($tradeId, 'void');
                    return ['error' => 409, 'message' => 'Nicht mehr möglich – ein Sticker ist inzwischen kein Doppelter mehr'];
                }
                // normale Karte vor Holo abgeben, Gold zuletzt
                $receiver = $it['giver_id'] === $trade['from_manager_id'] ? $trade['to_manager_id'] : $trade['from_manager_id'];
                $move->execute([$receiver, $tradeId, $ids[0]]);
            }
            $this->con->prepare("UPDATE sticker_trade SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$tradeId]);
            $this->con->commit();
        } catch (\Throwable $e) {
            if ($this->con->inTransaction()) $this->con->rollBack();
            throw $e;
        }

        $this->notifyStickerTrade($trade['from_manager_id'], $managerId, 'Tauschangebot angenommen',
            'Die getauschten Sticker sind jetzt in deinem Album.');
        return ['status' => 'accepted'];
    }

    /** DELETE /sticker/trade/:id — wer das Angebot gemacht hat, zieht es zurück. */
    public function cancelStickerTrade(string $managerId, string $tradeId): array
    {
        if (!$this->stickerTradeReady()) return ['error' => 409, 'message' => 'Tauschen ist noch nicht eingerichtet'];
        $trade = $this->loadStickerTrade($tradeId);
        if (!$trade || $trade['from_manager_id'] !== $managerId) return ['error' => 404, 'message' => 'Tauschangebot nicht gefunden'];
        if ($trade['status'] !== 'pending') return ['error' => 409, 'message' => 'Tauschangebot ist nicht mehr offen'];
        $this->setStickerTradeStatus($tradeId, 'cancelled');
        return ['status' => 'cancelled'];
    }

    /**
     * GET /sticker/trade — offene Angebote (eingehend/ausgehend) + die letzten 20 abgeschlossenen der aktiven
     * Saison, aus Sicht des Managers: give = was er abgibt, get = was er bekommt. Offene Angebote, die nicht
     * mehr möglich sind (ein Sticker kein Doppelter mehr), werden dabei auf void gesetzt.
     */
    public function getStickerTrades(string $managerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $result = ['available' => $this->stickerTradeReady(), 'incoming' => [], 'outgoing' => [], 'history' => []];
        if (!$result['available'] || $seasonId === null) return $result;

        $q = $this->con->prepare(
            "(SELECT t.*, mf.manager_name AS from_name, mt.manager_name AS to_name
              FROM sticker_trade t JOIN manager mf ON mf.id = t.from_manager_id JOIN manager mt ON mt.id = t.to_manager_id
              WHERE t.season_id = :s1 AND (t.from_manager_id = :m1 OR t.to_manager_id = :m2) AND t.status = 'pending')
             UNION ALL
             (SELECT t.*, mf.manager_name, mt.manager_name
              FROM sticker_trade t JOIN manager mf ON mf.id = t.from_manager_id JOIN manager mt ON mt.id = t.to_manager_id
              WHERE t.season_id = :s2 AND (t.from_manager_id = :m3 OR t.to_manager_id = :m4) AND t.status != 'pending'
              ORDER BY COALESCE(t.responded_at, t.created_at) DESC LIMIT 20)"
        );
        $q->execute([':s1' => $seasonId, ':m1' => $managerId, ':m2' => $managerId, ':s2' => $seasonId, ':m3' => $managerId, ':m4' => $managerId]);
        $trades = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$trades) return $result;

        $ids = array_column($trades, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $iq = $this->con->prepare(
            "SELECT i.trade_id, i.sticker_id, i.giver_id, s.sticker_key
             FROM sticker_trade_item i JOIN sticker s ON s.id = i.sticker_id WHERE i.trade_id IN ($in)"
        );
        $iq->execute($ids);
        $items = [];
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $it) $items[$it['trade_id']][] = $it;

        $counts = []; // Sammlungen nur einmal je Manager laden
        $countsOf = function (string $id) use (&$counts, $seasonId) {
            return $counts[$id] ??= $this->stickerCounts($id, $seasonId);
        };

        foreach ($trades as $t) {
            $tradeItems = $items[$t['id']] ?? [];
            $status = $t['status'];
            if ($status === 'pending') {
                foreach ($tradeItems as $it) {
                    if (($countsOf($it['giver_id'])[$it['sticker_id']] ?? 0) < 2) { $status = 'void'; break; }
                }
                if ($status === 'void') $this->setStickerTradeStatus($t['id'], 'void');
            }
            $outgoing = $t['from_manager_id'] === $managerId;
            $row = [
                'id'           => $t['id'],
                'status'       => $status,
                'direction'    => $outgoing ? 'outgoing' : 'incoming',
                'partner'      => [
                    'manager_id'   => $outgoing ? $t['to_manager_id'] : $t['from_manager_id'],
                    'manager_name' => $outgoing ? $t['to_name'] : $t['from_name'],
                ],
                'give'         => array_values(array_map(fn($i) => $i['sticker_key'], array_filter($tradeItems, fn($i) => $i['giver_id'] === $managerId))),
                'get'          => array_values(array_map(fn($i) => $i['sticker_key'], array_filter($tradeItems, fn($i) => $i['giver_id'] !== $managerId))),
                'created_at'   => $t['created_at'],
                'responded_at' => $status !== $t['status'] ? date('Y-m-d H:i:s') : $t['responded_at'],
            ];
            if ($status === 'pending') $result[$outgoing ? 'outgoing' : 'incoming'][] = $row;
            else $result['history'][] = $row;
        }
        return $result;
    }

    private function loadStickerTrade(string $tradeId): ?array
    {
        $q = $this->con->prepare("SELECT id, from_manager_id, to_manager_id, status FROM sticker_trade WHERE id = ?");
        $q->execute([$tradeId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function setStickerTradeStatus(string $tradeId, string $status): void
    {
        $this->con->prepare("UPDATE sticker_trade SET status = ?, responded_at = NOW() WHERE id = ? AND status = 'pending'")
            ->execute([$status, $tradeId]);
    }

    /** Benachrichtigung (Einstellung sticker_trade), Absender = Tauschpartner. */
    private function notifyStickerTrade(string $receiverId, string $senderId, string $title, ?string $message): void
    {
        try {
            if (!$this->isNotificationEnabled($receiverId, 'sticker_trade')) return;
            $n = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
            $n->execute([$senderId]);
            $name = $n->fetchColumn() ?: 'Jemand';
            $this->createNotification($receiverId, "$title – $name", $message, $senderId);
        } catch (\Throwable $e) {
            error_log('notifyStickerTrade: ' . $e->getMessage());
        }
    }
}
