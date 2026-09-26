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
            // Werte aus der Simulation: aktive Manager meist komplett (≈80 %, gegen Saisonende), Ø ≈93 %, inaktiv ≈50 %
            'daily_pack_size'         => 3,       // Sticker im täglichen Pack (0 = kein Tages-Pack)
            'milestone_interval'      => 100,     // alle X Saisonpunkte eines Teams ein Pack
            'milestone_pack_size'     => 3,       // Sticker im Meilenstein-Pack (0 = aus)
            'matchday_best_pack_size' => 5,       // Sticker im Spieltagsbester-Pack (0 = aus)
            'guarantee_new'           => true,    // 1. Sticker jedes Packs garantiert neu (solange welche fehlen)
            'milestone_all_new'       => true,    // Meilenstein-Pack: alle Sticker garantiert neu
            'matchday_best_all_new'   => true,    // Spieltagsbester-Pack: alle Sticker garantiert neu
            'rarity_alpha'            => 0.8,     // Ziehgewicht = Marktwert^-α
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

        $packSql = fn(string $announced) =>
            "SELECT sp.id, sp.source, sp.source_key, sp.size, sp.created_at, $announced AS announced, l.name AS league_name
             FROM sticker_pack sp LEFT JOIN league l ON l.id = sp.league_id
             WHERE sp.manager_id = ? AND sp.season_id = ? AND sp.opened_at IS NULL
             ORDER BY sp.created_at ASC";
        try {
            $pq = $this->con->prepare($packSql('sp.announced_at IS NOT NULL'));
            $pq->execute([$managerId, $seasonId]);
        } catch (\Throwable $e) {
            // Spalte announced_at fehlt noch (Migration nicht eingespielt) → nichts groß ankündigen
            $pq = $this->con->prepare($packSql('1'));
            $pq->execute([$managerId, $seasonId]);
        }
        $rows = $pq->fetchAll(PDO::FETCH_ASSOC);

        // Anlass aus dem source_key: Meilenstein-Schwelle bzw. Spieltag (Nummer per matchday_id nachschlagen)
        $matchdayIds = [];
        foreach ($rows as $p) {
            if ($p['source'] === 'matchday_best') $matchdayIds[] = explode(':', $p['source_key'])[2] ?? '';
        }
        $mdNumbers = [];
        if ($matchdayIds) {
            $in = implode(',', array_fill(0, count($matchdayIds), '?'));
            $mq = $this->con->prepare("SELECT id, number FROM matchday WHERE id IN ($in)");
            $mq->execute($matchdayIds);
            $mdNumbers = array_column($mq->fetchAll(PDO::FETCH_ASSOC), 'number', 'id');
        }

        $state['packs'] = array_map(function ($p) use ($mdNumbers) {
            $parts = explode(':', $p['source_key']);
            return [
                'id' => $p['id'], 'source' => $p['source'], 'size' => (int) $p['size'],
                'created_at' => $p['created_at'], 'league_name' => $p['league_name'],
                'announced' => (bool) $p['announced'],
                'milestone_points' => $p['source'] === 'milestone' ? (int) ($parts[2] ?? 0) : null,
                'matchday_number'  => $p['source'] === 'matchday_best' && isset($mdNumbers[$parts[2] ?? ''])
                    ? (int) $mdNumbers[$parts[2]] : null,
            ];
        }, $rows);

        $state['collection']      = $this->getStickerCollection($managerId, $seasonId);
        $state['ignored_days']    = $this->stickerIgnoredDays($managerId);
        $state['trades_incoming'] = $this->countIncomingStickerTrades($managerId, $seasonId);
        return $state;
    }

    /**
     * Desinteresse-Signal fürs Frontend: an wie vielen verschiedenen Tagen wurden eingeblendete Packs
     * weggeklickt (angekündigt, aber bis heute ungeöffnet) — gezählt nur seit dem zuletzt geöffneten
     * Pack. Wer ein Pack öffnet (aus der Einblendung oder später im Album), setzt den Zähler zurück.
     * Ab einer Schwelle bietet die Einblendung "Nicht mehr anzeigen" an.
     */
    private function stickerIgnoredDays(string $managerId): int
    {
        try {
            $q = $this->con->prepare(
                "SELECT COUNT(DISTINCT DATE(announced_at)) FROM sticker_pack
                 WHERE manager_id = :m AND announced_at IS NOT NULL AND opened_at IS NULL
                   AND announced_at > COALESCE(
                       (SELECT MAX(opened_at) FROM sticker_pack WHERE manager_id = :m2), '1970-01-01')"
            );
            $q->execute([':m' => $managerId, ':m2' => $managerId]);
            return (int) $q->fetchColumn();
        } catch (\Throwable $e) {
            return 0; // Spalte announced_at fehlt noch (Migration)
        }
    }

    /**
     * Packs als "groß angekündigt" markieren (Ankündigungs-Dialog geschlossen: aufgerissen oder "Später
     * öffnen") — gilt geräteübergreifend. Nur eigene Packs; bereits markierte bleiben unverändert.
     */
    public function markStickerPacksAnnounced(string $managerId, array $packIds): int
    {
        $packIds = array_values(array_filter($packIds, 'is_string'));
        if (!$packIds) return 0;
        $in = implode(',', array_fill(0, count($packIds), '?'));
        $q = $this->con->prepare(
            "UPDATE sticker_pack SET announced_at = NOW()
             WHERE manager_id = ? AND announced_at IS NULL AND id IN ($in)"
        );
        $q->execute([$managerId, ...$packIds]);
        return $q->rowCount();
    }

    /**
     * Alle Manager mit Album (aktiv in mind. einer Liga mit sticker_enabled) und ihr Fortschritt in der
     * aktiven Saison — für die Sammler-Rangliste; sortiert nach Anzahl verschiedener Sticker.
     * Mit $viewerId zusätzlich je Sammler die Tauschmöglichkeiten: trade_get = seine Doppelten, die dem
     * Betrachter fehlen, trade_give = Doppelte des Betrachters, die ihm fehlen.
     */
    public function getStickerCollectors(?string $viewerId = null): array
    {
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null || !$this->stickerAlbumReady($seasonId)) return ['season_id' => $seasonId, 'total' => 0, 'collectors' => []];

        $t = $this->con->prepare("SELECT COUNT(*) FROM sticker WHERE season_id = ?");
        $t->execute([$seasonId]);
        $q = $this->con->prepare(
            "SELECT m.id, m.manager_name,
                    COUNT(DISTINCT p.sticker_id) AS have, COUNT(p.id) AS pulled,
                    COALESCE(SUM(p.holo = 'silver'), 0) AS silver, COALESCE(SUM(p.holo = 'gold'), 0) AS gold
             FROM manager m
             LEFT JOIN sticker_pull p
                 ON p.manager_id = m.id AND p.sticker_id IN (SELECT id FROM sticker WHERE season_id = ?)
             WHERE m.status = 'active'
               AND EXISTS (SELECT 1 FROM manager_league ml JOIN league l ON l.id = ml.league_id
                           WHERE ml.manager_id = m.id AND ml.status = 'active' AND l.sticker_enabled = 1)
             GROUP BY m.id, m.manager_name
             ORDER BY have DESC, m.manager_name ASC"
        );
        $q->execute([$seasonId]);
        $collectors = array_map(fn($r) => [
            'manager_id' => $r['id'], 'manager_name' => $r['manager_name'],
            'have' => (int) $r['have'], 'pulled' => (int) $r['pulled'],
            'silver' => (int) $r['silver'], 'gold' => (int) $r['gold'],
        ], $q->fetchAll(PDO::FETCH_ASSOC));

        if ($viewerId !== null) {
            // alle Sammlungen der Saison auf einmal: manager_id → sticker_id → Anzahl
            $cq = $this->con->prepare(
                "SELECT p.manager_id, p.sticker_id, COUNT(*) AS cnt FROM sticker_pull p JOIN sticker s ON s.id = p.sticker_id
                 WHERE s.season_id = ? GROUP BY p.manager_id, p.sticker_id"
            );
            $cq->execute([$seasonId]);
            $all = [];
            foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) $all[$r['manager_id']][$r['sticker_id']] = (int) $r['cnt'];
            $mine = $all[$viewerId] ?? [];
            foreach ($collectors as &$c) {
                $theirs = $all[$c['manager_id']] ?? [];
                $c['trade_get'] = $c['trade_give'] = 0;
                if ($c['manager_id'] === $viewerId) continue;
                foreach ($theirs as $sid => $n) if ($n >= 2 && !isset($mine[$sid])) $c['trade_get']++;
                foreach ($mine as $sid => $n) if ($n >= 2 && !isset($theirs[$sid])) $c['trade_give']++;
            }
            unset($c);
        }

        return ['season_id' => $seasonId, 'total' => (int) $t->fetchColumn(), 'collectors' => $collectors];
    }

    /** Sammlung eines (anderen) Managers der aktiven Saison — null, wenn er kein Album hat. */
    public function getStickerCollectionOf(string $managerId): ?array
    {
        if (!$this->isStickerEnabledForManager($managerId)) return null;
        $seasonId = $this->getActiveSeasonId();
        $m = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
        $m->execute([$managerId]);
        $name = $m->fetchColumn();
        if ($name === false) return null;
        $ready = $seasonId !== null && $this->stickerAlbumReady($seasonId);
        return [
            'manager_id'   => $managerId,
            'manager_name' => $name,
            'season_id'    => $seasonId,
            'collection'   => $ready ? $this->getStickerCollection($managerId, $seasonId) : [],
        ];
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
     * Idempotent (source_key) — erneutes Abschließen vergibt nichts doppelt. Bereits zurückliegende
     * Spieltage holt backfillStickerPacks() nach (beim Album-Abgleich).
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
     * Rückwirkende Vergabe für die Saison (beim Album-Abgleich, POST /sticker/album/sync): in jeder Liga mit
     * sticker_enabled alle bisher erreichten Punkte-Meilensteine je Team und alle Spieltagssiege (höchste
     * Punkte unter den gewerteten Teams, bei Gleichstand alle) aus den vorhandenen team_rating-Zeilen.
     * Gleiche source_keys wie grantStickerMatchdayPacks() → idempotent und deckungsgleich mit der
     * Live-Vergabe; bereits vergebene Packs werden übersprungen. Tages-Packs lassen sich nicht nachholen.
     */
    public function backfillStickerPacks(string $seasonId): array
    {
        $result = ['milestone' => 0, 'matchday_best' => 0];
        if (!$this->stickerAlbumReady($seasonId)) return $result;
        $cfg = $this->stickerConfig();
        $interval = (int) $cfg['milestone_interval'];

        try {
            $leagues = $this->con->query("SELECT id, db_name FROM league WHERE sticker_enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $result; // Migration fehlt
        }

        foreach ($leagues as $league) {
            $db = $this->createConnection($_ENV['DB_HOST'], $league['db_name'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
            $q = $db->prepare(
                "SELECT t.id AS team_id, t.manager_id, tr.matchday_id, tr.points, tr.invalid
                 FROM team t JOIN team_rating tr ON tr.team_id = t.id
                 WHERE t.season_id = ?"
            );
            $q->execute([$seasonId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            // Meilensteine: je Team alle Schwellen bis zur aktuellen Saisonpunktzahl
            $teams = [];
            foreach ($rows as $r) {
                $teams[$r['team_id']] ??= ['manager_id' => $r['manager_id'], 'total' => 0];
                $teams[$r['team_id']]['total'] += (int) $r['points'];
            }
            if ($interval > 0) {
                foreach ($teams as $teamId => $t) {
                    for ($threshold = $interval; $threshold <= $t['total']; $threshold += $interval) {
                        if ($this->grantStickerPack($t['manager_id'], $seasonId, 'milestone', "milestone:{$teamId}:{$threshold}", $league['id'], $cfg['milestone_pack_size'])) {
                            $result['milestone']++;
                        }
                    }
                }
            }

            // Spieltagssiege: je Spieltag die gewerteten Teams mit der höchsten Punktzahl (> 0)
            $byMatchday = [];
            foreach ($rows as $r) {
                if ((int) $r['invalid']) continue;
                $byMatchday[$r['matchday_id']][] = $r;
            }
            foreach ($byMatchday as $matchdayId => $mdRows) {
                $best = max(array_map(fn($r) => (int) $r['points'], $mdRows));
                if ($best <= 0) continue;
                foreach ($mdRows as $r) {
                    if ((int) $r['points'] !== $best) continue;
                    if ($this->grantStickerPack($r['manager_id'], $seasonId, 'matchday_best', "matchday_best:{$r['team_id']}:{$matchdayId}", $league['id'], $cfg['matchday_best_pack_size'])) {
                        $result['matchday_best']++;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Öffnet ein eigenes, ungeöffnetes Pack: würfelt die Karten serverseitig (Gewicht = Marktwert^-α;
     * garantiert neu, solange Sticker fehlen: bei Meilenstein-/Spieltagsbester-Packs alle Karten, sonst die
     * erste; Holo je Karte) und speichert sie.
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

        // Wie viele Karten dieses Packs sind garantiert neu? Meilenstein/Spieltagsbester: alle, sonst die erste
        $allNew = ($pack['source'] === 'milestone' && $cfg['milestone_all_new'])
            || ($pack['source'] === 'matchday_best' && $cfg['matchday_best_all_new']);

        $cards = [];
        for ($k = 0; $k < (int) $pack['size']; $k++) {
            $i = ($allNew || ($cfg['guarantee_new'] && $k === 0)) ? $drawMissing() : $drawAny();
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
