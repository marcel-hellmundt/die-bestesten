<?php

/**
 * Lukaten-Konto — Lukaten sind das eine Zahlungsmittel der App (Konzept: docs/lukaten-economy-concept.md).
 * Referenzpunkt: 1 Eintrag = 1 Lukate ≈ 1 Cent. Ein Konto je Manager, unabhängig von Liga und Saison.
 *
 * Kontostand = Kontobuch + Tipps − alte Shop-Käufe
 *   Kontobuch        lukaten_transaction (globale DB): Startbonus, Einträge, Euro-Käufe, Pack-Käufe — je Bewegung
 *                    eine Zeile, der Schlüssel (source_key) ist je Manager eindeutig und macht jede Buchung idempotent.
 *   Tipps            live aus h2h_prediction aller Ligen des Managers: −Einsätze + Einsatz × Quote der gewonnenen.
 *                    Bewusst nicht ins Kontobuch gespiegelt — Tipp-Tabelle (Liga-DB) und Kontobuch (globale DB)
 *                    lassen sich nicht in einer Transaktion halten, so kann nichts auseinanderlaufen.
 *   alte Shop-Käufe  sticker_shop_purchase der Liga-DBs (Käufe vor der Umstellung); neue Käufe stehen im Kontobuch.
 * Gezählt wird ab der Saison der Umstellung (since_season). Je Saison gibt es einen Startbonus je Manager — einen,
 * egal in wie vielen Ligen er spielt. Wer nur in einer Liga spielt, hat damit im Moment der Umstellung genau so
 * viele Lukaten wie vorher (dort wurde mit 100 je Liga und Saison gerechnet). Wer in mehreren Ligen spielt, hat
 * jetzt ein Konto mit einem Startbonus, von dem die Tipps und Käufe aller seiner Ligen abgehen.
 */
trait LukatenAccountTrait
{
    /** Regeln und Preise — hier an einer Stelle, bis sie endgültig feststehen. */
    protected function lukatenAccountConfig(): array
    {
        return [
            'since_season'  => '2026-07-01', // Start der Saison der Umstellung: ab ihr zählen Tipps und alte Shop-Käufe zum Konto
            'season_bonus'  => 100,          // Startbonus je Manager und Saison (einer, unabhängig von der Zahl der Ligen)
            'entries_since' => '2026-10-07', // Lukaten für Einträge: nur Spieltage mit Anpfiff ab diesem Tag
            'max_payout'    => null,         // Obergrenze für den möglichen Gewinn eines Tipps (Einsatz × Quote); null = keine
            // Pack-Preise im Klebrigsten-Shop (Schlüssel wie StickerShopTrait::stickerShopOffers()). Maßstab: ein ganzer
            // Spieltag Einträge (rund 500 Lukaten) = 5 normale Packs, 15 Sticker — gut die Hälfte dessen, was ein aktiver
            // Manager in einer Woche ohnehin zieht (rund 25). Mit 60 je Pack hätten Einträge das Tempo verdoppelt.
            // Leiter 100/200/300/300: direkt gegen Euro gibt es je Angebot +50 % (einzelnes Pack) bis +88 % (Kiste) mehr als
            // über gekaufte Lukaten, steigend mit dem Betrag (Vergleich: /verwaltung/ui-tests → Preise).
            'pack_prices'   => ['l-small' => 100, 'l-big' => 200, 'l-club' => 300, 'l-special' => 300],
            // Lukaten gegen Euro [Cent, Lukaten]: 1 Lukate ≈ 1 Cent, mit jedem größeren Bündel etwas mehr je Euro
            // (+0 / +6 / +10 / +14 %). Die Schlüssel sind fest (stehen in sticker_eur_purchase), lk-300 bringt 320.
            'eur_bundles'   => ['lk-200' => [199, 200], 'lk-300' => [299, 320], 'lk-550' => [499, 550], 'lk-800' => [699, 800]],
        ];
    }

    private ?bool $lukatenReadyCache = null;
    private ?array $lukatenSeasonCache = null;
    private array $lukatenLeagueCons = [];
    private array $lukatenSynced = [];

    /**
     * Name des Locks je Manager (GET_LOCK auf der globalen Verbindung): Pack-Kauf und Tipp-Einsatz prüfen erst den
     * Kontostand und buchen dann — ohne Lock ließe sich dasselbe Guthaben parallel zweimal ausgeben.
     * MySQL-Lock-Namen sind auf 64 Zeichen begrenzt, deshalb gehasht.
     */
    protected function lukatenLockName(string $managerId): string
    {
        return 'lukaten:' . md5($managerId);
    }

    /** Kontobuch vorhanden? (Migration) — je Request einmal geprüft. */
    private function lukatenLedgerReady(): bool
    {
        if ($this->lukatenReadyCache !== null) return $this->lukatenReadyCache;
        try {
            $this->con->query("SELECT 1 FROM lukaten_transaction LIMIT 1");
            return $this->lukatenReadyCache = true;
        } catch (\Throwable $e) {
            return $this->lukatenReadyCache = false;
        }
    }

    /**
     * Eine Bewegung buchen (+ Gutschrift, − Ausgabe). Ein zweiter Versuch mit demselben Schlüssel bucht nichts —
     * true, wenn neu gebucht wurde.
     */
    private function bookLukaten(string $managerId, float $amount, string $source, string $sourceKey,
                                 ?string $seasonId = null, ?string $packId = null): bool
    {
        $q = $this->con->prepare(
            "INSERT IGNORE INTO lukaten_transaction (manager_id, amount, source, source_key, season_id, pack_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $q->execute([$managerId, $amount, $source, $sourceKey, $seasonId, $packId]);
        return $q->rowCount() > 0;
    }

    /** Saisons, die zum Konto zählen: [id => start_date], älteste (Saison der Umstellung) zuerst. */
    private function lukatenSeasons(): array
    {
        if ($this->lukatenSeasonCache !== null) return $this->lukatenSeasonCache;
        $q = $this->con->prepare("SELECT id, start_date FROM season WHERE start_date >= ? ORDER BY start_date ASC");
        $q->execute([$this->lukatenAccountConfig()['since_season']]);
        return $this->lukatenSeasonCache = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Ligen, in denen der Manager aktives Mitglied ist: [{id, name, db_name, joined_at}]. */
    private function lukatenLeagues(string $managerId): array
    {
        $q = $this->con->prepare(
            "SELECT l.id, l.name, l.db_name, ml.joined_at FROM manager_league ml JOIN league l ON l.id = ml.league_id
             WHERE ml.manager_id = ? AND ml.status = 'active' AND l.db_name IS NOT NULL AND l.db_name != ''"
        );
        $q->execute([$managerId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Verbindung zur Liga-DB — die bestehende, falls es die Liga des Requests ist; sonst je Liga einmal geöffnet. */
    private function lukatenLeagueConnection(array $league): PDO
    {
        if ($this->con_league && ($GLOBALS['auth_league_id'] ?? null) === $league['id']) return $this->con_league;
        return $this->lukatenLeagueCons[$league['id']]
            ??= $this->createConnection($_ENV['DB_HOST'], $league['db_name'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
    }

    /**
     * Bringt das Kontobuch auf den Stand (beim Abruf, wie das Tages-Pack): der Startbonus der laufenden Saison,
     * einer je Manager (season:{season_id}). Der Startbonus der Saison der Umstellung wird immer nachgetragen —
     * ihre Tipps und Shop-Käufe zählen zum Konto, auch wenn der erste Abruf erst später kommt.
     */
    private function syncLukatenAccount(string $managerId): void
    {
        if (isset($this->lukatenSynced[$managerId])) return; // je Request einmal
        $this->lukatenSynced[$managerId] = true;
        $seasons = $this->lukatenSeasons();
        if (!$seasons) return;
        $leagues = $this->lukatenLeagues($managerId);
        if (!$leagues) return; // ohne Liga kein Guthaben
        $bonus     = (float) $this->lukatenAccountConfig()['season_bonus'];
        $cutoverId = array_key_first($seasons);
        // Saison der Umstellung — nicht für Manager, die erst in einer späteren Saison dazugekommen sind
        $nextStart = array_values($seasons)[1] ?? null;
        $joined    = min(array_map(fn($l) => $l['joined_at'] === null ? '' : substr((string) $l['joined_at'], 0, 10), $leagues));
        if ($nextStart === null || $joined < $nextStart) {
            $this->bookLukaten($managerId, $bonus, 'season_bonus', "season:$cutoverId", $cutoverId);
        }
        $activeId = $this->getActiveSeasonId();
        if ($activeId !== null && $activeId !== $cutoverId && isset($seasons[$activeId])) {
            $this->bookLukaten($managerId, $bonus, 'season_bonus', "season:$activeId", $activeId);
        }
    }

    /**
     * Fehlt in dieser Liga-DB nur die Tabelle oder Spalte (Liga ohne H2H-Tipps bzw. ohne Shop-Migration)? Dann zählt
     * die Liga an dieser Stelle nicht mit. Jeder andere Fehler muss durchschlagen — ein stillschweigend zu hoher
     * Kontostand ließe sich ausgeben.
     */
    private function lukatenFeatureMissing(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array($e->getCode(), ['42S02', '42S22'], true);
    }

    /**
     * Bestandteile des Kontostands: ledger (Summe des Kontobuchs), stakes/payouts (Tipps seit der Umstellung, alle
     * Ligen), legacy_shop (alte Shop-Käufe) und balance. $excludeMatchId lässt den eigenen Einsatz auf genau dieses
     * Match aus — nötig, um beim Ändern eines Einsatzes den vollen Rahmen zu prüfen.
     */
    private function lukatenParts(string $managerId, ?string $excludeMatchId = null): array
    {
        $lq = $this->con->prepare("SELECT COALESCE(SUM(amount), 0) FROM lukaten_transaction WHERE manager_id = ?");
        $lq->execute([$managerId]);
        $parts = ['ledger' => (float) $lq->fetchColumn(), 'stakes' => 0.0, 'payouts' => 0.0, 'legacy_shop' => 0.0];

        $seasonIds = array_keys($this->lukatenSeasons());
        if ($seasonIds) {
            $in = implode(',', array_fill(0, count($seasonIds), '?'));
            foreach ($this->lukatenLeagues($managerId) as $league) {
                $db = $this->lukatenLeagueConnection($league);
                try {
                    $bq = $db->prepare(
                        "SELECT hp.match_id, hp.stake, hp.odds, hp.result
                         FROM h2h_prediction hp JOIN h2h_match hm ON hm.id = hp.match_id
                         WHERE hp.manager_id = ? AND hp.stake IS NOT NULL AND hm.season_id IN ($in)"
                    );
                    $bq->execute([$managerId, ...$seasonIds]);
                    foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if ($excludeMatchId !== null && $r['match_id'] === $excludeMatchId) continue;
                        $parts['stakes'] += (float) $r['stake'];
                        // result kommt von der DB mitunter mit eingestreuten Null-Bytes (siehe getH2HPredictionState())
                        if (str_replace("\0", '', (string) $r['result']) === 'won') {
                            $parts['payouts'] += (float) $r['stake'] * (float) $r['odds'];
                        }
                    }
                } catch (\Throwable $e) {
                    if (!$this->lukatenFeatureMissing($e)) throw $e; // sonst: Liga ohne H2H-Tipps
                }
                try {
                    $sq = $db->prepare("SELECT COALESCE(SUM(price), 0) FROM sticker_shop_purchase WHERE manager_id = ? AND season_id IN ($in)");
                    $sq->execute([$managerId, ...$seasonIds]);
                    $parts['legacy_shop'] += (float) $sq->fetchColumn();
                } catch (\Throwable $e) {
                    if (!$this->lukatenFeatureMissing($e)) throw $e; // sonst: Liga ohne Shop-Tabelle (migrate_sticker_shop.sql)
                }
            }
        }

        $parts['balance'] = round($parts['ledger'] - $parts['stakes'] + $parts['payouts'] - $parts['legacy_shop'], 2);
        return $parts;
    }

    /** Kontostand des Managers. Ohne Kontobuch (Migration fehlt) 0. */
    public function getLukatenBalance(string $managerId, ?string $excludeMatchId = null): float
    {
        if ($managerId === '' || !$this->lukatenLedgerReady()) return 0.0;
        $this->syncLukatenAccount($managerId);
        return $this->lukatenParts($managerId, $excludeMatchId)['balance'];
    }

    /** GET /lukaten — Kontostand für die Anzeige (Topbar): {ready, balance}. */
    public function getLukatenState(string $managerId): array
    {
        $ready = $this->lukatenLedgerReady();
        return ['ready' => $ready, 'balance' => $ready ? $this->getLukatenBalance($managerId) : null];
    }

    /**
     * SQL-Bedingung für maintainer_contribution mc: welche Zeilen als Eintrag zählen. Das sind alle — Einsatz und
     * Note gehören je Bewertung einem Manager, die Statistik jedem, von dem eine gültige Angabe stammt
     * (PlayerRatingTrait::assignContribution()). Bei Einsatz und Note fängt die Bedingung zusätzlich Zeilen aus
     * der Zeit davor ab (mehrere Manager für denselben Wert): dort zählt nur die früheste.
     */
    private function lukatenFirstEntrySql(): string
    {
        return "(mc.contribution_type = 'stats' OR NOT EXISTS (
                    SELECT 1 FROM maintainer_contribution o
                    WHERE o.player_rating_id = mc.player_rating_id AND o.contribution_type = mc.contribution_type
                      AND (o.created_at < mc.created_at OR (o.created_at = mc.created_at AND o.id < mc.id))))";
    }

    /**
     * Beim Spieltagsabschluss (PATCH /matchday/:id completed=true): je Eintrag zu einer Bewertung dieses Spieltags
     * 1 Lukate — eine Buchung je Manager und Spieltag (entries:{matchday_id}), erneutes Abschließen bucht nichts
     * doppelt. Ein Eintrag ist eine Zeile in maintainer_contribution (Einsatz, Note oder Statistik eines Spielers)
     * und gehört dem, dessen Wert beim Abschluss gilt: Wer Einsatz oder Note korrigiert, übernimmt den Eintrag,
     * der Vorgänger bekommt nichts; bei der Statistik hat jeder einen Eintrag, von dem eine gültige Angabe stammt
     * (PlayerRatingTrait::assignContribution()). Gezählt wird erst beim Abschluss, bis dahin kann ein Eintrag also
     * noch wechseln. Nur Spieltage mit Anpfiff ab entries_since. Rückgabe: [manager_id => Lukaten].
     */
    public function creditLukatenEntriesForMatchday(string $matchdayId): array
    {
        if (!$this->lukatenLedgerReady()) return [];
        $mq = $this->con->prepare("SELECT number, season_id, kickoff_date FROM matchday WHERE id = ?");
        $mq->execute([$matchdayId]);
        $md = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$md || $md['kickoff_date'] === null || substr((string) $md['kickoff_date'], 0, 10) < $this->lukatenAccountConfig()['entries_since']) {
            return [];
        }

        $q = $this->con->prepare(
            "SELECT mc.manager_id, COUNT(*) AS cnt
             FROM maintainer_contribution mc
             JOIN player_rating pr ON pr.id = mc.player_rating_id
             WHERE pr.matchday_id = ? AND {$this->lukatenFirstEntrySql()}
             GROUP BY mc.manager_id"
        );
        $q->execute([$matchdayId]);

        $credited = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $count = (int) $r['cnt'];
            if ($count <= 0 || !$this->bookLukaten($r['manager_id'], (float) $count, 'entries', "entries:$matchdayId", $md['season_id'])) continue;
            $credited[$r['manager_id']] = $count;
            try {
                $this->createNotification(
                    $r['manager_id'], "Spieltag {$md['number']}: +$count Lukaten",
                    "Für deine $count " . ($count === 1 ? 'Eintrag' : 'Einträge') . " bei Noten, Einsätzen und Statistik.", null
                );
            } catch (\Throwable $e) {
                error_log('creditLukatenEntriesForMatchday notify: ' . $e->getMessage());
            }
        }
        return $credited;
    }

    /**
     * Einträge des Managers auf noch nicht abgeschlossenen Spieltagen — sie werden beim Abschluss gebucht.
     * Je Art (participation = Einsatz, note = Note, stats = Statistik). Bis zum Abschluss können sie noch an
     * jemanden übergehen, der den Wert korrigiert. Ein Spieltag, für den er schon eine Buchung hat (abgeschlossen
     * und wieder geöffnet), zählt nicht mehr — da kommt nichts dazu.
     */
    private function lukatenPendingEntries(string $managerId): array
    {
        $byType = ['participation' => 0, 'note' => 0, 'stats' => 0];
        try {
            $bq = $this->con->prepare("SELECT source_key FROM lukaten_transaction WHERE manager_id = ? AND source = 'entries'");
            $bq->execute([$managerId]);
            $booked = array_flip($bq->fetchAll(PDO::FETCH_COLUMN)); // 'entries:{matchday_id}'

            $q = $this->con->prepare(
                "SELECT pr.matchday_id, mc.contribution_type, COUNT(*) AS cnt
                 FROM maintainer_contribution mc
                 JOIN player_rating pr ON pr.id = mc.player_rating_id
                 JOIN matchday md ON md.id = pr.matchday_id
                 WHERE mc.manager_id = ? AND md.completed = 0 AND md.kickoff_date >= ? AND {$this->lukatenFirstEntrySql()}
                 GROUP BY pr.matchday_id, mc.contribution_type"
            );
            $q->execute([$managerId, $this->lukatenAccountConfig()['entries_since']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($booked["entries:{$r['matchday_id']}"])) $byType[$r['contribution_type']] += (int) $r['cnt'];
            }
        } catch (\Throwable $e) {
            // Einträge nicht lesbar → 0
        }
        return ['count' => array_sum($byType), 'by_type' => $byType];
    }

    /** Lukaten-Bündel gegen Euro: [key => {name, price_cents, lukaten}]. */
    protected function lukatenEurBundles(): array
    {
        $bundles = [];
        foreach ($this->lukatenAccountConfig()['eur_bundles'] as $key => [$cents, $lukaten]) {
            $bundles[$key] = ['name' => "$lukaten Lukaten", 'price_cents' => $cents, 'lukaten' => $lukaten];
        }
        return $bundles;
    }

    /**
     * GET /lukaten/account — das Konto im Einzelnen (Seite /lukaten): Kontostand, Summen je Herkunft, Einträge der
     * laufenden Spieltage (noch nicht gebucht), die Buchungen des Kontobuchs, Regeln mit Preisen und die eigenen
     * offenen Euro-Zahlungen.
     */
    public function getLukatenAccount(string $managerId): array
    {
        if (!$this->lukatenLedgerReady()) return ['ready' => false, 'balance' => null];
        $this->syncLukatenAccount($managerId);
        $parts = $this->lukatenParts($managerId);
        $cfg   = $this->lukatenAccountConfig();

        $tq = $this->con->prepare("SELECT source, SUM(amount) AS amount FROM lukaten_transaction WHERE manager_id = ? GROUP BY source");
        $tq->execute([$managerId]);
        $bySource = array_map('floatval', $tq->fetchAll(PDO::FETCH_KEY_PAIR));

        $lq = $this->con->prepare(
            "SELECT lt.source, lt.source_key, lt.amount, lt.created_at, s.start_date AS season_start,
                    sp.pack_kind, c.name AS club_name
             FROM lukaten_transaction lt
             LEFT JOIN season s ON s.id = lt.season_id
             LEFT JOIN sticker_pack sp ON sp.id = lt.pack_id
             LEFT JOIN club c ON c.id = sp.club_id
             WHERE lt.manager_id = ?
             ORDER BY lt.created_at DESC, lt.id DESC LIMIT 50"
        );
        $lq->execute([$managerId]);
        $rows = $lq->fetchAll(PDO::FETCH_ASSOC);

        // der Spieltag steht bei Einträgen als ID im Buchungsschlüssel (entries:{matchday_id})
        $keyPart = fn(array $r, int $i) => explode(':', (string) $r['source_key'])[$i] ?? '';
        $matchdayIds = array_values(array_unique(array_map(fn($r) => $keyPart($r, 1), array_filter($rows, fn($r) => $r['source'] === 'entries'))));
        $mdNumbers = [];
        if ($matchdayIds) {
            $mq = $this->con->prepare('SELECT id, number FROM matchday WHERE id IN (' . implode(',', array_fill(0, count($matchdayIds), '?')) . ')');
            $mq->execute($matchdayIds);
            $mdNumbers = $mq->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $kinds   = $this->stickerPackKinds();
        $bundles = $this->lukatenEurBundles();

        $transactions = array_map(fn($r) => [
            'source'          => $r['source'],
            'amount'          => (float) $r['amount'],
            'created_at'      => $r['created_at'],
            'season_start'    => $r['season_start'],
            'matchday_number' => $r['source'] === 'entries' && isset($mdNumbers[$keyPart($r, 1)]) ? (int) $mdNumbers[$keyPart($r, 1)] : null,
            'pack_name'       => $r['source'] === 'pack' ? ($kinds[$r['pack_kind']]['name'] ?? null) : null,
            'club_name'       => $r['club_name'],
        ], $rows);

        $offers = $this->stickerShopOffers();
        $eur    = $this->getStickerEurState($managerId, $this->getActiveSeasonId());
        return [
            'ready'   => true,
            'balance' => $parts['balance'],
            'totals'  => [
                'start'   => $bySource['season_bonus'] ?? 0.0,
                'entries' => $bySource['entries'] ?? 0.0,
                'eur'     => ($bySource['eur'] ?? 0.0) + ($bySource['eur_cancel'] ?? 0.0),
                'packs'   => ($bySource['pack'] ?? 0.0) - $parts['legacy_shop'],
                'stakes'  => -$parts['stakes'],
                'payouts' => $parts['payouts'],
            ],
            'pending_entries' => $this->lukatenPendingEntries($managerId),
            'transactions'    => $transactions,
            'rules' => [
                'season_bonus' => $cfg['season_bonus'],
                'max_payout'   => $cfg['max_payout'],
                'packs'        => array_values(array_map(
                    fn($o) => ['kind' => $o['kind'], 'name' => $o['name'], 'size' => $o['size'], 'price' => $o['price']], $offers)),
                'eur_bundles'  => array_map(
                    fn($key, $b) => ['key' => $key, 'amount_cents' => $b['price_cents'], 'lukaten' => $b['lukaten']], array_keys($bundles), $bundles),
            ],
            'eur' => [
                'available' => $eur['available'],
                'paypal_me' => $eur['paypal_me'],
                'pending'   => array_values(array_filter($eur['pending'], fn($p) => isset($bundles[$p['offer_key']]))),
            ],
        ];
    }

    /**
     * GET /lukaten/overview (Admin) — alle Konten: je aktivem Manager mit Liga der Kontostand und woher er kommt,
     * dazu die Summen (in_circulation = alle Kontostände zusammen). stakes/payouts = alle Einsätze und alle
     * ausgezahlten Tippgewinne dieser Konten, auch offene Einsätze — daraus die Bank als Gegenseite der Tipps;
     * der Shop ist die Gegenseite der Pack-Käufe (−packs). Bringt dabei jedes Konto auf den Stand.
     */
    public function getLukatenOverview(): array
    {
        if (!$this->lukatenLedgerReady()) return ['ready' => false, 'accounts' => [], 'totals' => null];

        $mq = $this->con->query(
            "SELECT DISTINCT m.id, m.manager_name FROM manager m
             JOIN manager_league ml ON ml.manager_id = m.id AND ml.status = 'active'
             WHERE m.status = 'active' ORDER BY m.manager_name"
        );
        $sq = $this->con->prepare("SELECT source, SUM(amount) FROM lukaten_transaction WHERE manager_id = ? GROUP BY source");

        $accounts = [];
        $totals = ['in_circulation' => 0.0, 'start' => 0.0, 'entries' => 0.0, 'eur' => 0.0, 'packs' => 0.0, 'bets' => 0.0,
                   'stakes' => 0.0, 'payouts' => 0.0];
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $this->syncLukatenAccount($m['id']);
            $parts = $this->lukatenParts($m['id']);
            $sq->execute([$m['id']]);
            $src = array_map('floatval', $sq->fetchAll(PDO::FETCH_KEY_PAIR));
            $row = [
                'manager_id'   => $m['id'],
                'manager_name' => $m['manager_name'],
                'balance'      => $parts['balance'],
                'start'        => $src['season_bonus'] ?? 0.0,
                'entries'      => $src['entries'] ?? 0.0,
                'eur'          => ($src['eur'] ?? 0.0) + ($src['eur_cancel'] ?? 0.0),
                'packs'        => ($src['pack'] ?? 0.0) - $parts['legacy_shop'],
                'bets'         => round($parts['payouts'] - $parts['stakes'], 2),
            ];
            $accounts[] = $row;
            $totals['in_circulation'] += $row['balance'];
            foreach (['start', 'entries', 'eur', 'packs', 'bets'] as $k) $totals[$k] += $row[$k];
            $totals['stakes']  += $parts['stakes'];
            $totals['payouts'] += $parts['payouts'];
        }
        usort($accounts, fn($a, $b) => $b['balance'] <=> $a['balance'] ?: strcmp($a['manager_name'], $b['manager_name']));
        return ['ready' => true, 'accounts' => $accounts, 'totals' => array_map(fn($v) => round($v, 2), $totals)];
    }

    /**
     * GET /lukaten/history (Admin) — Verlauf: wie viele Lukaten an jedem Tag seit dem Stichtag (since_season) auf den
     * Konten lagen, je Manager, dazu Bank (Gegenseite der Tipps: Einsätze − ausgezahlte Gewinne) und Shop (für Packs
     * ausgegeben). Aus denselben Quellen wie der Kontostand, nach Tag aufsummiert:
     *   Kontobuch          Tag der Buchung (Pack-Käufe gehen zugleich an den Shop)
     *   Tipp-Einsätze      Tag, an dem der Tipp abgegeben wurde (gehen an die Bank)
     *   Tippgewinne        Tag des Anpfiffs des Spieltags — einen Zeitpunkt der Auswertung gibt es nicht, der
     *                      Abschluss liegt meist ein paar Tage danach (kommen von der Bank)
     *   alte Shop-Käufe    Tag des Kaufs (gehen an den Shop)
     * Die Werte je Tag sind Stände am Tagesende; der letzte Tag entspricht GET /lukaten/overview.
     */
    public function getLukatenHistory(): array
    {
        $since = $this->lukatenAccountConfig()['since_season'];
        if (!$this->lukatenLedgerReady()) return ['ready' => false, 'since' => $since, 'days' => [], 'managers' => [], 'bank' => [], 'shop' => []];

        $mq = $this->con->query(
            "SELECT DISTINCT m.id, m.manager_name FROM manager m
             JOIN manager_league ml ON ml.manager_id = m.id AND ml.status = 'active'
             WHERE m.status = 'active' ORDER BY m.manager_name"
        );
        $names = $mq->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (array_keys($names) as $managerId) $this->syncLukatenAccount($managerId);

        $today = date('Y-m-d');
        $days = [];
        for ($t = strtotime($since); $t <= strtotime($today); $t = strtotime('+1 day', $t)) $days[] = date('Y-m-d', $t);
        $index = array_flip($days);
        $last = count($days) - 1;
        // Bewegung auf den Tag legen; was vor dem Stichtag oder (Anpfiff eines laufenden Spieltags) nach heute liegt, an den Rand
        $at = function (?string $date) use ($index, $since, $today, $last): int {
            $d = substr((string) $date, 0, 10);
            if ($d === '' || $d < $since) return 0;
            if ($d > $today) return $last;
            return $index[$d] ?? $last;
        };

        $delta = [];                                   // manager_id => [Tag-Index => Veränderung]
        $bank = array_fill(0, count($days), 0.0);
        $shop = array_fill(0, count($days), 0.0);
        $add = function (string $managerId, int $i, float $amount) use (&$delta, $names): bool {
            if (!isset($names[$managerId])) return false;
            $delta[$managerId][$i] = ($delta[$managerId][$i] ?? 0.0) + $amount;
            return true;
        };

        $lq = $this->con->query("SELECT manager_id, amount, source, created_at FROM lukaten_transaction");
        foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $i = $at($r['created_at']);
            if ($add($r['manager_id'], $i, (float) $r['amount']) && $r['source'] === 'pack') $shop[$i] -= (float) $r['amount'];
        }

        $seasonIds = array_keys($this->lukatenSeasons());
        if ($seasonIds) {
            $in = implode(',', array_fill(0, count($seasonIds), '?'));
            $kq = $this->con->prepare("SELECT id, kickoff_date FROM matchday WHERE season_id IN ($in)");
            $kq->execute($seasonIds);
            $kickoff = $kq->fetchAll(PDO::FETCH_KEY_PAIR);
            $memberQ = $this->con->prepare("SELECT manager_id FROM manager_league WHERE league_id = ? AND status = 'active'");

            $leagues = $this->con->query("SELECT id, name, db_name FROM league WHERE db_name IS NOT NULL AND db_name != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leagues as $league) {
                // wie beim Kontostand zählen nur die Ligen, in denen der Manager aktives Mitglied ist
                $memberQ->execute([$league['id']]);
                $members = array_flip($memberQ->fetchAll(PDO::FETCH_COLUMN));
                $db = $this->lukatenLeagueConnection($league);
                try {
                    $bq = $db->prepare(
                        "SELECT hp.manager_id, hp.stake, hp.odds, hp.result, hp.created_at, hm.matchday_id
                         FROM h2h_prediction hp JOIN h2h_match hm ON hm.id = hp.match_id
                         WHERE hp.stake IS NOT NULL AND hm.season_id IN ($in)"
                    );
                    $bq->execute($seasonIds);
                    foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (!isset($members[$r['manager_id']])) continue;
                        $stake = (float) $r['stake'];
                        $i = $at($r['created_at']);
                        if (!$add($r['manager_id'], $i, -$stake)) continue;
                        $bank[$i] += $stake;
                        if (str_replace("\0", '', (string) $r['result']) === 'won') {
                            $payout = $stake * (float) $r['odds'];
                            $j = max($i, $at($kickoff[$r['matchday_id']] ?? $r['created_at']));
                            $add($r['manager_id'], $j, $payout);
                            $bank[$j] -= $payout;
                        }
                    }
                } catch (\Throwable $e) {
                    if (!$this->lukatenFeatureMissing($e)) throw $e; // Liga ohne H2H-Tipps
                }
                try {
                    $sq = $db->prepare("SELECT manager_id, price, created_at FROM sticker_shop_purchase WHERE season_id IN ($in)");
                    $sq->execute($seasonIds);
                    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (!isset($members[$r['manager_id']])) continue;
                        $i = $at($r['created_at']);
                        if ($add($r['manager_id'], $i, -(float) $r['price'])) $shop[$i] += (float) $r['price'];
                    }
                } catch (\Throwable $e) {
                    if (!$this->lukatenFeatureMissing($e)) throw $e; // Liga ohne Shop-Tabelle
                }
            }
        }

        // aus den Veränderungen je Tag die Stände am Tagesende
        $running = function (array $perDay) use ($days): array {
            $sum = 0.0;
            $out = [];
            foreach (array_keys($days) as $i) {
                $sum += $perDay[$i] ?? 0.0;
                $out[] = round($sum, 2);
            }
            return $out;
        };
        $managers = [];
        foreach ($names as $managerId => $name) {
            $managers[] = ['manager_id' => $managerId, 'manager_name' => $name, 'balance' => $running($delta[$managerId] ?? [])];
        }
        usort($managers, fn($a, $b) => end($b['balance']) <=> end($a['balance']) ?: strcmp($a['manager_name'], $b['manager_name']));

        return ['ready' => true, 'since' => $since, 'days' => $days, 'managers' => $managers, 'bank' => $running($bank), 'shop' => $running($shop)];
    }

    /**
     * POST /lukaten/buy_eur — Lukaten gegen Euro über denselben PayPal.me-Ablauf wie die Euro-Packs
     * (StickerShopEurTrait, Tabelle sticker_eur_purchase): Kauf anlegen (pending, Kauf-Code) — damit ist er nur
     * vorgemerkt. Der Käufer zahlt per PayPal.me; erst wenn ein Admin die Zahlung bestätigt, werden die Lukaten
     * gutgeschrieben (eur:{purchase_id}, handleStickerEurPurchase()). Ein Storno davor bucht nichts.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['purchase_id','code','amount_cents','paypal_url','lukaten','balance'].
     */
    public function buyLukatenEur(string $managerId, string $offerKey): array
    {
        if (!$this->lukatenLedgerReady() || !$this->stickerEurReady()) return ['error' => 409, 'message' => 'Lukaten-Käufe sind noch nicht eingerichtet'];
        $bundle = $this->lukatenEurBundles()[$offerKey] ?? null;
        if (!$bundle) return ['error' => 422, 'message' => 'Unbekanntes Angebot'];
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null) return ['error' => 409, 'message' => 'Keine aktive Saison'];

        $pq = $this->con->prepare("SELECT COUNT(*) FROM sticker_eur_purchase WHERE manager_id = ? AND season_id = ? AND status = 'pending'");
        $pq->execute([$managerId, $seasonId]);
        if ((int) $pq->fetchColumn() >= self::EUR_MAX_PENDING) {
            return ['error' => 409, 'message' => 'Bitte erst deine offenen Zahlungen erledigen'];
        }

        $this->con->beginTransaction();
        try {
            $purchaseId = $this->con->query("SELECT UUID()")->fetchColumn();
            $code = $this->newStickerEurCode();
            $this->con->prepare(
                "INSERT INTO sticker_eur_purchase (id, manager_id, season_id, offer_key, amount_cents, code) VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$purchaseId, $managerId, $seasonId, $offerKey, $bundle['price_cents'], $code]);
            // Gutgeschrieben wird erst, wenn ein Admin die Zahlung bestätigt (handleStickerEurPurchase()) — bis dahin
            // ist der Kauf nur vorgemerkt und die Lukaten sind nicht nutzbar
            $this->con->commit();
        } catch (\Throwable $e) {
            if ($this->con->inTransaction()) $this->con->rollBack();
            error_log('buyLukatenEur: ' . $e->getMessage());
            return ['error' => 409, 'message' => 'Kauf konnte nicht angelegt werden'];
        }

        try {
            $n = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
            $n->execute([$managerId]);
            $managerName = (string) $n->fetchColumn();
            $amount = $this->formatEur($bundle['price_cents']);
            foreach ($this->getAdminManagerIds() as $adminId) {
                $this->createNotification(
                    $adminId, "Lukaten-Kauf: $managerName – {$bundle['name']} ($amount)",
                    "Code $code · $amount per PayPal an {$this->paypalMeName()} · Zahlung prüfen und im Shop unter „Euro-Käufe“ bestätigen oder stornieren",
                    $managerId
                );
            }
        } catch (\Throwable $e) {
            error_log('buyLukatenEur notify: ' . $e->getMessage());
        }

        return [
            'purchase_id'  => $purchaseId,
            'code'         => $code,
            'amount_cents' => $bundle['price_cents'],
            'paypal_url'   => $this->paypalMeUrl($bundle['price_cents']),
            'lukaten'      => $bundle['lukaten'],
            'balance'      => $this->getLukatenBalance($managerId),
        ];
    }
}
