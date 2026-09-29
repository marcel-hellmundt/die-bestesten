<?php

/**
 * "Die Klebrigsten" — Packs: Vergabe (tägliches Pack, Punkte-Meilensteine, Spieltagsbester, Geburtstag, Weihnachten),
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
            // Sonder-Packs (Größe/Zusammensetzung vorläufig): Geburtstag (manager.date_of_birth) und Weihnachten (24.–26.12.)
            'birthday_pack_size'      => 5,       // 0 = aus
            'birthday_guaranteed_new' => 2,
            'birthday_since'          => '2026-09-29', // erst Geburtstage ab Einführung — sonst gäbe es beim Start rückwirkend Packs für alle
            'christmas_pack_size'     => 5,       // 0 = aus
            'christmas_guaranteed_new' => 2,
            // mind. so viele Karten "episch oder besser" (Gewichtungs-Marktwert > epic_min_price) je Pack-Quelle
            'min_epic'                => ['birthday' => 1, 'christmas' => 1],
            'epic_min_price'          => 3_500_000, // = DEFAULT_TIER_THRESHOLDS.epic im Frontend (album.model.ts)
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

    /**
     * Idempotente Vergabe — true, wenn das Pack neu angelegt wurde. $guaranteedNew = so viele Karten garantiert neu
     * (null = Regel je source beim Öffnen).
     */
    private function grantStickerPack(string $managerId, string $seasonId, string $source, string $sourceKey, ?string $leagueId, int $size,
                                      ?int $guaranteedNew = null): bool
    {
        if ($size <= 0) return false;
        $cols = ['manager_id', 'season_id', 'source', 'source_key', 'league_id', 'size'];
        $vals = [$managerId, $seasonId, $source, $sourceKey, $leagueId, $size];
        if ($guaranteedNew !== null) { $cols[] = 'guaranteed_new'; $vals[] = min($guaranteedNew, $size); }
        $q = $this->con->prepare(
            'INSERT IGNORE INTO sticker_pack (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
        );
        $q->execute($vals);
        return $q->rowCount() > 0;
    }

    /**
     * Sonder-Packs beim Abruf von GET /sticker/me (deutsche Zeit, $today = Y-m-d):
     * - Geburtstag: jüngster Geburtstag (manager.date_of_birth, 29.2. in Nicht-Schaltjahren am 28.2.) liegt heute oder
     *   früher und ab birthday_since → Pack birthday:{Jahr}. Wer an dem Tag nicht online war, bekommt es beim nächsten
     *   Login — auch in der nächsten Saison bzw. nach dem Einfrieren des Albums (dann ins aktive Album).
     * - Weihnachten: nur wer vom 24. bis 26.12. online ist → Pack christmas:{Jahr}.
     * Idempotent über source_key; fehlt die Migration (ENUM-Werte), passiert nichts.
     */
    private function grantSpecialStickerPacks(string $managerId, string $seasonId, string $today): void
    {
        $cfg = $this->stickerConfig();
        [$year, $monthDay] = [(int) substr($today, 0, 4), substr($today, 5)];
        try {
            $bq = $this->con->prepare("SELECT date_of_birth FROM manager WHERE id = ?");
            $bq->execute([$managerId]);
            $dob = $bq->fetchColumn();
            if ($dob) {
                $birthday = function (int $y) use ($dob): string {
                    $md = substr((string) $dob, 5, 5);
                    if ($md === '02-29' && !checkdate(2, 29, $y)) $md = '02-28';
                    return "$y-$md";
                };
                $last = $birthday($year) <= $today ? $year : $year - 1;
                if ($birthday($last) >= $cfg['birthday_since']) {
                    $this->grantStickerPack($managerId, $seasonId, 'birthday', "birthday:$last", null,
                        $cfg['birthday_pack_size'], $cfg['birthday_guaranteed_new']);
                }
            }
            if ($monthDay >= '12-24' && $monthDay <= '12-26') {
                $this->grantStickerPack($managerId, $seasonId, 'christmas', "christmas:$year", null,
                    $cfg['christmas_pack_size'], $cfg['christmas_guaranteed_new']);
            }
        } catch (\Throwable $e) {
            // Migration 2026-09-29_sticker_pack_special.sql fehlt noch (source-ENUM) → keine Sonder-Packs
        }
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
        $this->grantSpecialStickerPacks($managerId, $seasonId, $today);

        $packSql = fn(string $announced, string $club, string $kind = 'NULL') =>
            "SELECT sp.id, sp.source, sp.source_key, sp.size, sp.created_at, $announced AS announced, $club AS club_id, $kind AS pack_kind, l.name AS league_name
             FROM sticker_pack sp LEFT JOIN league l ON l.id = sp.league_id
             WHERE sp.manager_id = ? AND sp.season_id = ? AND sp.opened_at IS NULL
             ORDER BY sp.created_at ASC";
        // Spalten announced_at / club_id fehlen evtl. noch (Migrationen nicht eingespielt) → schrittweise ohne
        $rows = null;
        foreach ([['sp.announced_at IS NOT NULL', 'sp.club_id', 'sp.pack_kind'], ['sp.announced_at IS NOT NULL', 'sp.club_id', 'NULL'],
                  ['sp.announced_at IS NOT NULL', 'NULL', 'NULL'], ['1', 'NULL', 'NULL']] as [$announced, $club, $kind]) {
            try {
                $pq = $this->con->prepare($packSql($announced, $club, $kind));
                $pq->execute([$managerId, $seasonId]);
                $rows = $pq->fetchAll(PDO::FETCH_ASSOC);
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }
        $rows ??= [];

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
                'shop_offer'       => $p['source'] === 'shop' ? ($parts[1] ?? null) : null,
                'club_id'          => $p['club_id'],
                'pack_kind'        => $p['pack_kind'],
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
     * Mit $withUnopened (nur Admins) zusätzlich unopened = Anzahl ungeöffneter Packs der aktiven Saison (+ unopened_types)
     * und packs = [{type, total, opened}] je Pack-Art: erhalten und davon geöffnet.
     */
    public function getStickerCollectors(?string $viewerId = null, bool $withUnopened = false): array
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
            // alle Sammlungen der Saison auf einmal: manager_id → sticker_id → [Anzahl, davon tauschbar]
            // (Karten aus unbezahlten Euro-Käufen sind nicht tauschbar)
            $lk = $this->stickerLockedJoin('p');
            $cq = $this->con->prepare(
                "SELECT p.manager_id, p.sticker_id, COUNT(*) AS cnt, SUM(NOT {$lk['locked']}) AS tradeable
                 FROM sticker_pull p JOIN sticker s ON s.id = p.sticker_id {$lk['join']}
                 WHERE s.season_id = ? GROUP BY p.manager_id, p.sticker_id"
            );
            $cq->execute([$seasonId]);
            $all = [];
            foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) $all[$r['manager_id']][$r['sticker_id']] = (int) $r['tradeable'];
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

        if ($withUnopened) {
            // je Manager nach Art (Shop-Packs nach pack_kind normal/big/club/special, sonst nach source):
            // erhalten + davon geöffnet; ungeöffnet = Differenz
            try {
                $uq = $this->con->prepare(
                    "SELECT manager_id, COALESCE(pack_kind, source) AS type, COUNT(*) AS total, SUM(opened_at IS NOT NULL) AS opened
                     FROM sticker_pack WHERE season_id = ?
                     GROUP BY manager_id, COALESCE(pack_kind, source)"
                );
                $uq->execute([$seasonId]);
            } catch (PDOException $e) {
                // Spalte pack_kind fehlt noch (migrate_sticker_pack_kind.sql) → nur nach source
                $uq = $this->con->prepare(
                    "SELECT manager_id, source AS type, COUNT(*) AS total, SUM(opened_at IS NOT NULL) AS opened
                     FROM sticker_pack WHERE season_id = ? GROUP BY manager_id, source"
                );
                $uq->execute([$seasonId]);
            }
            $types = [];
            foreach ($uq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $types[$r['manager_id']][] = ['type' => $r['type'], 'total' => (int) $r['total'], 'opened' => (int) $r['opened']];
            }
            foreach ($collectors as &$c) {
                $packs = $types[$c['manager_id']] ?? [];
                $unopened = [];
                foreach ($packs as $p) {
                    if ($p['total'] > $p['opened']) $unopened[] = ['type' => $p['type'], 'count' => $p['total'] - $p['opened']];
                }
                usort($unopened, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['type'], $b['type']));
                $c['unopened'] = array_sum(array_column($unopened, 'count'));
                $c['unopened_types'] = $unopened;
                $c['packs'] = $packs;
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

    /**
     * Sammlung: je gezogenem Sticker Anzahl, Holo-Anzahlen, Zeitpunkt des ersten Zugs und locked = davon aus
     * noch unbezahlten Euro-Käufen (bis zur Bestätigung nicht tauschbar, siehe StickerShopEurTrait).
     */
    private function getStickerCollection(string $managerId, string $seasonId): array
    {
        $lk = $this->stickerLockedJoin('p');
        $q = $this->con->prepare(
            "SELECT s.sticker_key, COUNT(*) AS cnt,
                    SUM(p.holo = 'silver') AS silver, SUM(p.holo = 'gold') AS gold,
                    MIN(p.created_at) AS first_at, SUM({$lk['locked']}) AS locked
             FROM sticker_pull p JOIN sticker s ON s.id = p.sticker_id {$lk['join']}
             WHERE p.manager_id = ? AND s.season_id = ?
             GROUP BY s.id, s.sticker_key"
        );
        $q->execute([$managerId, $seasonId]);
        return array_map(fn($r) => [
            'key' => $r['sticker_key'], 'count' => (int) $r['cnt'],
            'silver' => (int) $r['silver'], 'gold' => (int) $r['gold'], 'first_at' => $r['first_at'],
            'locked' => (int) $r['locked'],
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
     * erste; Sonder-Packs mind. min_epic Karten "episch oder besser"; Holo je Karte) und speichert sie.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['pack' => …, 'cards' => [{key, holo, is_new}]].
     */
    public function openStickerPack(string $managerId, string $packId): array
    {
        try {
            try {
                $pq = $this->con->prepare("SELECT id, season_id, source, size, opened_at, club_id, guaranteed_new, holo_min FROM sticker_pack WHERE id = ? AND manager_id = ?");
                $pq->execute([$packId, $managerId]);
            } catch (\Throwable $e) {
                // Spalte holo_min fehlt noch (migrate_sticker_pack_kind.sql)
                $pq = $this->con->prepare("SELECT id, season_id, source, size, opened_at, club_id, guaranteed_new, NULL AS holo_min FROM sticker_pack WHERE id = ? AND manager_id = ?");
                $pq->execute([$packId, $managerId]);
            }
        } catch (\Throwable $e) {
            // Spalten club_id/guaranteed_new fehlen noch (migrate_sticker_shop_pack.sql) → wie bisher
            $pq = $this->con->prepare("SELECT id, season_id, source, size, opened_at, NULL AS club_id, NULL AS guaranteed_new, NULL AS holo_min FROM sticker_pack WHERE id = ? AND manager_id = ?");
            $pq->execute([$packId, $managerId]);
        }
        $pack = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$pack) return ['error' => 404, 'message' => 'Pack nicht gefunden'];
        if ($pack['opened_at'] !== null) return ['error' => 409, 'message' => 'Pack wurde bereits geöffnet'];

        $sq = $this->con->prepare("SELECT id, sticker_key, price, club_id FROM sticker WHERE season_id = ?");
        $sq->execute([$pack['season_id']]);
        $stickers = $sq->fetchAll(PDO::FETCH_ASSOC);
        if (!$stickers) return ['error' => 409, 'message' => 'Album der Saison existiert noch nicht'];
        // Vereins-Pack (Shop): nur Sticker dieses Vereins
        if ($pack['club_id'] !== null) {
            $stickers = array_values(array_filter($stickers, fn($s) => $s['club_id'] === $pack['club_id']));
            if (!$stickers) return ['error' => 409, 'message' => 'Verein ist nicht im Album'];
        }

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

        // Epische (oder bessere) Sticker — für Packs mit Garantie "mind. N episch" (Sonder-Packs)
        $isEpic = fn(int $i): bool => (int) $stickers[$i]['price'] > $cfg['epic_min_price'];
        $minEpic = (int) ($cfg['min_epic'][$pack['source']] ?? 0);
        $hasEpic = $minEpic > 0 && array_filter(array_keys($stickers), $isEpic);

        $drawAny = function (bool $onlyEpic = false) use ($weights, $total, $rand, $isEpic): int {
            $sum = $onlyEpic ? 0.0 : $total;
            if ($onlyEpic) foreach ($weights as $i => $w) if ($isEpic($i)) $sum += $w;
            $r = $rand() * $sum;
            foreach ($weights as $i => $w) {
                if ($onlyEpic && !$isEpic($i)) continue;
                $r -= $w;
                if ($r < 0) return $i;
            }
            return count($weights) - 1;
        };
        $drawMissing = function (bool $onlyEpic = false) use ($weights, $stickers, &$owned, $rand, $drawAny, $isEpic): int {
            $ok = fn(int $i): bool => !isset($owned[$stickers[$i]['id']]) && (!$onlyEpic || $isEpic($i));
            $missingTotal = 0.0;
            foreach ($weights as $i => $w) if ($ok($i)) $missingTotal += $w;
            if ($missingTotal <= 0) return $drawAny($onlyEpic);
            $r = $rand() * $missingTotal;
            foreach ($weights as $i => $w) {
                if (!$ok($i)) continue;
                $r -= $w;
                if ($r < 0) return $i;
            }
            return $drawAny($onlyEpic);
        };

        // Wie viele Karten dieses Packs sind garantiert neu? Shop-Packs: je Angebot (guaranteed_new),
        // Meilenstein/Spieltagsbester: alle, sonst die erste
        $allNew = ($pack['source'] === 'milestone' && $cfg['milestone_all_new'])
            || ($pack['source'] === 'matchday_best' && $cfg['matchday_best_all_new']);
        $guaranteed = $pack['guaranteed_new'] !== null
            ? (int) $pack['guaranteed_new']
            : ($allNew ? (int) $pack['size'] : ($cfg['guarantee_new'] ? 1 : 0));

        $cards = [];
        $epics = 0;
        $size = (int) $pack['size'];
        for ($k = 0; $k < $size; $k++) {
            // Epic-Garantie: fehlen noch so viele epische Karten, wie Plätze übrig sind → nur noch aus den epischen ziehen
            $forceEpic = $hasEpic && $minEpic - $epics >= $size - $k;
            $i = $k < $guaranteed ? $drawMissing($forceEpic) : $drawAny($forceEpic);
            if ($isEpic($i)) $epics++;
            $sticker = $stickers[$i];
            $isNew = !isset($owned[$sticker['id']]);
            $owned[$sticker['id']] = true;
            $h = $rand();
            $holo = $h < $cfg['holo_gold_chance'] ? 'gold'
                : ($h < $cfg['holo_gold_chance'] + $cfg['holo_silver_chance'] ? 'silver' : null);
            $cards[] = ['sticker_id' => $sticker['id'], 'key' => $sticker['sticker_key'], 'holo' => $holo, 'is_new' => $isNew];
        }

        // Holo-Garantie (Special-Pack): fehlende Holos auf zufälligen normalen Karten nachwürfeln — Gold im selben
        // Verhältnis wie sonst (Gold-Chance / Holo-Chance gesamt), sonst Silber
        $holoMin = (int) ($pack['holo_min'] ?? 0);
        $plain = array_keys(array_filter($cards, fn($c) => $c['holo'] === null));
        shuffle($plain);
        $missingHolo = $holoMin - (count($cards) - count($plain));
        $goldShare = $cfg['holo_gold_chance'] / max(1e-9, $cfg['holo_gold_chance'] + $cfg['holo_silver_chance']);
        for ($m = 0; $m < $missingHolo && $plain; $m++) {
            $cards[array_pop($plain)]['holo'] = $rand() < $goldShare ? 'gold' : 'silver';
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
