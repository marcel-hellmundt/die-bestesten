<?php

/**
 * "Die Klebrigsten" — Packs: Vergabe (tägliches Pack, Punkte-Meilensteine, Spieltagsbester),
 * Öffnen (serverseitiges Würfeln) und Sammlung. Das Album ist global je Manager + Saison;
 * aktiv ist es für Manager, die in mindestens einer Liga mit league.sticker_enabled spielen.
 */
trait StickerPackTrait
{
    /**
     * Regeln — vorerst hier als Variablen (Werte aus der Simulation), bis sie festgelegt sind.
     * Packgrößen gelten für neu vergebene Packs (die Größe wird beim Vergeben im Pack gespeichert).
     */
    protected function stickerConfig(): array
    {
        return [
            'daily_pack_size'         => 3,       // Sticker im täglichen Pack (0 = kein Tages-Pack)
            'milestone_interval'      => 100,     // alle X Saisonpunkte eines Teams ein Pack
            'milestone_pack_size'     => 3,       // Sticker im Meilenstein-Pack (0 = aus)
            'matchday_best_pack_size' => 3,       // Sticker im Spieltagsbester-Pack (0 = aus)
            'guarantee_new'           => true,    // 1. Sticker jedes Packs garantiert neu (solange welche fehlen)
            'rarity_alpha'            => 0.5,     // Ziehgewicht = Marktwert^-α
            'holo_silver_chance'      => 0.01,    // je gezogenem Sticker
            'holo_gold_chance'        => 0.001,
            'min_price'               => 500_000, // Untergrenze der Gewichtung (wie MIN_PRICE im Frontend)
        ];
    }

    /** Hat der Manager ein Album? (aktiv in mind. einer Liga mit sticker_enabled) */
    public function isStickerEnabledForManager(string $managerId): bool
    {
        try {
            $q = $this->con->prepare(
                "SELECT 1 FROM manager_league ml JOIN league l ON l.id = ml.league_id
                 WHERE ml.manager_id = ? AND ml.status = 'active' AND l.sticker_enabled = 1 LIMIT 1"
            );
            $q->execute([$managerId]);
            return (bool) $q->fetchColumn();
        } catch (\Throwable $e) {
            return false; // Migration noch nicht eingespielt
        }
    }

    public function isStickerEnabledForLeague(?string $leagueId): bool
    {
        if (!$leagueId) return false;
        try {
            $q = $this->con->prepare("SELECT sticker_enabled FROM league WHERE id = ? LIMIT 1");
            $q->execute([$leagueId]);
            return (bool) $q->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function updateLeagueStickerEnabled(string $id, bool $enabled): void
    {
        $q = $this->con->prepare("UPDATE league SET sticker_enabled = :enabled WHERE id = :id");
        $q->execute([':enabled' => $enabled ? 1 : 0, ':id' => $id]);
    }

    /** Wurde das Album der Saison schon eingefroren? Erst dann werden Packs vergeben. */
    private function stickerAlbumReady(string $seasonId): bool
    {
        try {
            $q = $this->con->prepare("SELECT 1 FROM sticker WHERE season_id = ? LIMIT 1");
            $q->execute([$seasonId]);
            return (bool) $q->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Idempotente Vergabe — true, wenn das Pack neu angelegt wurde. */
    private function grantStickerPack(string $managerId, string $seasonId, string $source, string $sourceKey, ?string $leagueId, int $size): bool
    {
        if ($size <= 0) return false;
        $q = $this->con->prepare(
            "INSERT IGNORE INTO sticker_pack (manager_id, season_id, source, source_key, league_id, size)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $q->execute([$managerId, $seasonId, $source, $sourceKey, $leagueId, $size]);
        return $q->rowCount() > 0;
    }

    /**
     * Status fürs Frontend (beim App-Start + Datumswechsel abgefragt): vergibt dabei das tägliche Pack
     * ("App öffnen"), liefert ungeöffnete Packs und die Sammlung der aktiven Saison.
     */
    public function getMyStickerState(string $managerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $enabled  = $this->isStickerEnabledForManager($managerId);
        $ready    = $enabled && $seasonId !== null && $this->stickerAlbumReady($seasonId);
        $state = ['enabled' => $enabled, 'album_ready' => $ready, 'season_id' => $seasonId, 'packs' => [], 'collection' => []];
        if (!$ready) return $state;

        // Tageswechsel nach deutscher Zeit (PHP-Default-Zeitzone des Servers ist nicht festgelegt)
        $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $this->grantStickerPack($managerId, $seasonId, 'daily', "daily:{$today}", null, $this->stickerConfig()['daily_pack_size']);

        $pq = $this->con->prepare(
            "SELECT sp.id, sp.source, sp.size, sp.created_at, l.name AS league_name
             FROM sticker_pack sp LEFT JOIN league l ON l.id = sp.league_id
             WHERE sp.manager_id = ? AND sp.season_id = ? AND sp.opened_at IS NULL
             ORDER BY sp.created_at ASC"
        );
        $pq->execute([$managerId, $seasonId]);
        $state['packs'] = array_map(fn($p) => [
            'id' => $p['id'], 'source' => $p['source'], 'size' => (int) $p['size'],
            'created_at' => $p['created_at'], 'league_name' => $p['league_name'],
        ], $pq->fetchAll(PDO::FETCH_ASSOC));

        $state['collection'] = $this->getStickerCollection($managerId, $seasonId);
        return $state;
    }

    /** Sammlung: je gezogenem Sticker Anzahl, Holo-Anzahlen und Zeitpunkt des ersten Zugs. */
    private function getStickerCollection(string $managerId, string $seasonId): array
    {
        $q = $this->con->prepare(
            "SELECT s.sticker_key, COUNT(*) AS cnt,
                    SUM(p.holo = 'silver') AS silver, SUM(p.holo = 'gold') AS gold,
                    MIN(p.created_at) AS first_at
             FROM sticker_pull p JOIN sticker s ON s.id = p.sticker_id
             WHERE p.manager_id = ? AND s.season_id = ?
             GROUP BY s.id, s.sticker_key"
        );
        $q->execute([$managerId, $seasonId]);
        return array_map(fn($r) => [
            'key' => $r['sticker_key'], 'count' => (int) $r['cnt'],
            'silver' => (int) $r['silver'], 'gold' => (int) $r['gold'], 'first_at' => $r['first_at'],
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Beim Abschließen eines Spieltags (PATCH /matchday/:id completed=true), nur wenn die aktuelle Liga
     * das Feature aktiv hat und das Album der Saison existiert: Meilenstein-Packs für jedes Team, dessen
     * Saisonpunkte mit diesem Spieltag eine neue Schwelle (Vielfache von milestone_interval) überschritten
     * haben, und ein Pack für den/die Spieltagsbesten (höchste Punkte unter den gewerteten Teams).
     * Idempotent (source_key) — erneutes Abschließen vergibt nichts doppelt; nicht rückwirkend.
     */
    public function grantStickerMatchdayPacks(string $matchdayId): array
    {
        $result = ['milestone' => 0, 'matchday_best' => 0];
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if (!$this->isStickerEnabledForLeague($leagueId)) return $result;

        $mq = $this->con->prepare("SELECT season_id FROM matchday WHERE id = ?");
        $mq->execute([$matchdayId]);
        $seasonId = $mq->fetchColumn();
        if (!$seasonId || !$this->stickerAlbumReady($seasonId)) return $result;

        $cfg = $this->stickerConfig();
        $q = $this->con_league->prepare(
            "SELECT t.id AS team_id, t.manager_id, tr.points, tr.invalid,
                    (SELECT COALESCE(SUM(tr2.points), 0) FROM team_rating tr2 WHERE tr2.team_id = t.id) AS total
             FROM team_rating tr JOIN team t ON t.id = tr.team_id
             WHERE tr.matchday_id = ?"
        );
        $q->execute([$matchdayId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $interval = (int) $cfg['milestone_interval'];
        foreach ($rows as $r) {
            if ($interval <= 0) break;
            $after  = (int) $r['total'];
            $before = $after - (int) $r['points'];
            for ($m = intdiv(max($before, 0), $interval) + 1; $m * $interval <= $after; $m++) {
                $threshold = $m * $interval;
                if ($this->grantStickerPack($r['manager_id'], $seasonId, 'milestone', "milestone:{$r['team_id']}:{$threshold}", $leagueId, $cfg['milestone_pack_size'])) {
                    $result['milestone']++;
                }
            }
        }

        $valid = array_filter($rows, fn($r) => !(int) $r['invalid']);
        $best  = $valid ? max(array_map(fn($r) => (int) $r['points'], $valid)) : 0;
        if ($best > 0) {
            foreach ($valid as $r) {
                if ((int) $r['points'] !== $best) continue;
                if ($this->grantStickerPack($r['manager_id'], $seasonId, 'matchday_best', "matchday_best:{$r['team_id']}:{$matchdayId}", $leagueId, $cfg['matchday_best_pack_size'])) {
                    $result['matchday_best']++;
                }
            }
        }
        return $result;
    }

    /**
     * Öffnet ein eigenes, ungeöffnetes Pack: würfelt die Karten serverseitig (Gewicht = Marktwert^-α;
     * 1. Karte garantiert neu, solange Sticker fehlen; Holo je Karte) und speichert sie.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['pack' => …, 'cards' => [{key, holo, is_new}]].
     */
    public function openStickerPack(string $managerId, string $packId): array
    {
        $pq = $this->con->prepare("SELECT id, season_id, source, size, opened_at FROM sticker_pack WHERE id = ? AND manager_id = ?");
        $pq->execute([$packId, $managerId]);
        $pack = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$pack) return ['error' => 404, 'message' => 'Pack nicht gefunden'];
        if ($pack['opened_at'] !== null) return ['error' => 409, 'message' => 'Pack wurde bereits geöffnet'];

        $sq = $this->con->prepare("SELECT id, sticker_key, price FROM sticker WHERE season_id = ?");
        $sq->execute([$pack['season_id']]);
        $stickers = $sq->fetchAll(PDO::FETCH_ASSOC);
        if (!$stickers) return ['error' => 409, 'message' => 'Album der Saison existiert noch nicht'];

        $oq = $this->con->prepare(
            "SELECT DISTINCT p.sticker_id FROM sticker_pull p JOIN sticker s ON s.id = p.sticker_id
             WHERE p.manager_id = ? AND s.season_id = ?"
        );
        $oq->execute([$managerId, $pack['season_id']]);
        $owned = array_fill_keys($oq->fetchAll(PDO::FETCH_COLUMN), true);

        $cfg = $this->stickerConfig();
        $weights = array_map(fn($s) => pow(max((int) $s['price'], $cfg['min_price']), -$cfg['rarity_alpha']), $stickers);
        $total = array_sum($weights);
        $rand = fn(): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;

        $drawAny = function () use ($weights, $total, $rand): int {
            $r = $rand() * $total;
            foreach ($weights as $i => $w) { $r -= $w; if ($r < 0) return $i; }
            return count($weights) - 1;
        };
        $drawMissing = function () use ($weights, $stickers, &$owned, $rand, $drawAny): int {
            $missingTotal = 0.0;
            foreach ($weights as $i => $w) if (!isset($owned[$stickers[$i]['id']])) $missingTotal += $w;
            if ($missingTotal <= 0) return $drawAny();
            $r = $rand() * $missingTotal;
            foreach ($weights as $i => $w) {
                if (isset($owned[$stickers[$i]['id']])) continue;
                $r -= $w;
                if ($r < 0) return $i;
            }
            return $drawAny();
        };

        $cards = [];
        for ($k = 0; $k < (int) $pack['size']; $k++) {
            $i = ($cfg['guarantee_new'] && $k === 0) ? $drawMissing() : $drawAny();
            $sticker = $stickers[$i];
            $isNew = !isset($owned[$sticker['id']]);
            $owned[$sticker['id']] = true;
            $h = $rand();
            $holo = $h < $cfg['holo_gold_chance'] ? 'gold'
                : ($h < $cfg['holo_gold_chance'] + $cfg['holo_silver_chance'] ? 'silver' : null);
            $cards[] = ['sticker_id' => $sticker['id'], 'key' => $sticker['sticker_key'], 'holo' => $holo, 'is_new' => $isNew];
        }

        $this->con->beginTransaction();
        try {
            // Gegen doppeltes Öffnen (zwei Tabs/Klicks): nur wer das Pack wirklich "umlegt", schreibt Karten
            $up = $this->con->prepare("UPDATE sticker_pack SET opened_at = NOW() WHERE id = ? AND opened_at IS NULL");
            $up->execute([$packId]);
            if ($up->rowCount() === 0) {
                $this->con->rollBack();
                return ['error' => 409, 'message' => 'Pack wurde bereits geöffnet'];
            }
            $ins = $this->con->prepare("INSERT INTO sticker_pull (pack_id, manager_id, sticker_id, slot, holo) VALUES (?, ?, ?, ?, ?)");
            foreach ($cards as $slot => $c) {
                $ins->execute([$packId, $managerId, $c['sticker_id'], $slot, $c['holo']]);
            }
            $this->con->commit();
        } catch (\Throwable $e) {
            $this->con->rollBack();
            throw $e;
        }

        return [
            'pack'  => ['id' => $pack['id'], 'source' => $pack['source'], 'size' => (int) $pack['size']],
            'cards' => array_map(fn($c) => ['key' => $c['key'], 'holo' => $c['holo'], 'is_new' => $c['is_new']], $cards),
        ];
    }
}
