<?php

/**
 * Lukaten-Konto — neuer Modus ab der nächsten Saison (Konzept: docs/lukaten-economy-concept.md).
 * Ein Konto je Manager, unabhängig von Liga und Saison: jede Bewegung ist eine Zeile in lukaten_transaction
 * (globale DB), der Kontostand ist die Summe. Ob eine Saison so läuft, steht in season.lukaten_mode:
 *   classic — bisheriger Rechenweg je Liga und Saison (H2HPredictionTrait::getManagerLukatenBudget())
 *   account — dieses Konto
 * Gebaut: Startbonus, Lukaten für Einträge, Pack-Käufe im Shop. Tippen rechnet in beiden Modi noch klassisch,
 * Lukaten gegen Euro gibt es noch nicht.
 * Ohne Migration 2026-10-07_lukaten_account.sql ist jede Saison classic.
 *
 * Vorschau: Development und Production teilen sich dieselbe Datenbank, der Modus der laufenden Saison darf zum
 * Ausprobieren deshalb nicht in der DB umgeschaltet werden. Stattdessen läuft ein einzelner Request im
 * Konto-Modus, wenn die Umgebung es erlaubt (LUKATEN_MODE_SWITCH=true im .env der API — nur Development), ein
 * Admin ihn schickt und der Header X-Lukaten-Preview: 1 gesetzt ist (Schalter unter /verwaltung/lukaten, je
 * Gerät). Buchungen der Vorschau sind markiert (lukaten_transaction.preview = 1) und vom echten Konto getrennt;
 * ein Pack-Kauf in der Vorschau bucht nur Lukaten ab und legt kein Pack an — das laufende Album bleibt unberührt.
 */
trait LukatenAccountTrait
{
    /** Regeln des Kontos — vorerst hier als Variablen, bis sie endgültig feststehen. */
    protected function lukatenAccountConfig(): array
    {
        return [
            'season_bonus'       => 20, // Startbonus je Saison, beim ersten Kontoabruf der Saison gebucht
            'entries_per_lukate' => 50, // jeder so-vielte Eintrag (Note, Einsatz, Statistik) bringt 1 Lukate
            // Geplante Lukaten-Bündel gegen Euro [Cent, Lukaten] — noch nicht kaufbar, nur zur Ansicht. Jedes kostet
            // so viel wie das Euro-Paket, dessen Packs man damit kaufen kann (StickerShopEurTrait): Special einzeln,
            // Handvoll, Stapel, Kiste.
            'eur_bundles'        => [[199, 9], [299, 15], [499, 30], [699, 44]],
        ];
    }

    /** Erlaubt diese Umgebung die Vorschau des Konto-Modus? */
    public function lukatenPreviewAvailable(): bool
    {
        return ($_ENV['LUKATEN_MODE_SWITCH'] ?? '') === 'true';
    }

    /** Läuft dieser Request in der Vorschau? (Umgebung erlaubt es, Admin, Header gesetzt) */
    public function isLukatenPreview(): bool
    {
        return $this->lukatenPreviewAvailable()
            && ($_SERVER['HTTP_X_LUKATEN_PREVIEW'] ?? '') === '1'
            && in_array('admin', $GLOBALS['auth_roles'] ?? [], true);
    }

    /**
     * Lukaten-Modus einer Saison für diesen Request: in der Vorschau immer account, sonst der Wert der Saison —
     * classic, solange nichts anderes gesetzt ist oder die Migration fehlt.
     */
    public function getSeasonLukatenMode(?string $seasonId): string
    {
        if ($seasonId === null) return 'classic';
        if ($this->isLukatenPreview()) return 'account';
        try {
            $q = $this->con->prepare("SELECT lukaten_mode FROM season WHERE id = ?");
            $q->execute([$seasonId]);
            return $q->fetchColumn() === 'account' ? 'account' : 'classic';
        } catch (\Throwable $e) {
            return 'classic';
        }
    }

    /** Kontobuch vorhanden? (Migration) */
    private function lukatenLedgerReady(): bool
    {
        try {
            $this->con->query("SELECT 1 FROM lukaten_transaction LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Eine Bewegung buchen (+ Gutschrift, − Ausgabe). Der Schlüssel ist je Manager eindeutig: ein zweiter Versuch
     * mit demselben Schlüssel bucht nichts — true, wenn neu gebucht wurde. In der Vorschau als preview markiert.
     */
    private function bookLukaten(string $managerId, float $amount, string $source, string $sourceKey,
                                 ?string $seasonId = null, ?string $packId = null): bool
    {
        $q = $this->con->prepare(
            "INSERT IGNORE INTO lukaten_transaction (manager_id, preview, amount, source, source_key, season_id, pack_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $q->execute([$managerId, $this->isLukatenPreview() ? 1 : 0, $amount, $source, $sourceKey, $seasonId, $packId]);
        return $q->rowCount() > 0;
    }

    /**
     * Ab wann Einträge zählen: ab dem Start der ersten Saison im Konto-Modus, danach fortlaufend über alle
     * Saisons. In der Vorschau ab dem Start der aktiven Saison — so zeigt das Vorschau-Konto, was die laufende
     * Saison bisher gebracht hätte.
     */
    private function lukatenEntriesSince(): ?string
    {
        try {
            $q = $this->isLukatenPreview()
                ? $this->con->query("SELECT start_date FROM season WHERE start_date <= CURDATE() ORDER BY start_date DESC LIMIT 1")
                : $this->con->query("SELECT MIN(start_date) FROM season WHERE lukaten_mode = 'account'");
            return $q->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Einträge des Managers, die für Lukaten zählen, je Art (participation = Einsatz, note = Note, stats =
     * Statistik). Grundlage ist maintainer_contribution; dort bekommt jeder einen Eintrag, der einen Wert speichert
     * — auch einen schon vorhandenen. Für Lukaten zählt je Bewertung und Art nur der früheste Eintrag, sonst ließen
     * sich fremde Einträge durch erneutes Speichern abgreifen.
     */
    private function countLukatenEntries(string $managerId, string $since): array
    {
        $q = $this->con->prepare(
            "SELECT mc.contribution_type, COUNT(*) AS cnt
             FROM maintainer_contribution mc
             WHERE mc.manager_id = ? AND mc.created_at >= ?
               AND NOT EXISTS (
                   SELECT 1 FROM maintainer_contribution o
                   WHERE o.player_rating_id = mc.player_rating_id AND o.contribution_type = mc.contribution_type
                     AND (o.created_at < mc.created_at OR (o.created_at = mc.created_at AND o.id < mc.id)))
             GROUP BY mc.contribution_type"
        );
        $q->execute([$managerId, $since]);
        $byType = ['participation' => 0, 'note' => 0, 'stats' => 0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $byType[$r['contribution_type']] = (int) $r['cnt'];
        return $byType;
    }

    /**
     * Bringt das Konto auf den Stand (beim Abruf, wie das Tages-Pack): bucht den Startbonus der Saison, falls er
     * fehlt, und je volle entries_per_lukate Einträge eine Lukate (Schlüssel entries:{n} — keine Schwelle wird
     * doppelt bezahlt, auch wenn Einträge wegfallen und wiederkommen). Rückgabe: Stand der Einträge.
     */
    private function syncLukatenAccount(string $managerId, string $seasonId): array
    {
        $cfg = $this->lukatenAccountConfig();
        $per = (int) $cfg['entries_per_lukate'];
        $entries = ['since' => null, 'per' => $per, 'count' => 0, 'by_type' => ['participation' => 0, 'note' => 0, 'stats' => 0],
                    'credited' => 0, 'to_next' => $per];

        $this->bookLukaten($managerId, (float) $cfg['season_bonus'], 'season_bonus', "season:$seasonId", $seasonId);

        $since = $this->lukatenEntriesSince();
        if ($since === null || $per <= 0) return $entries;
        try {
            $byType = $this->countLukatenEntries($managerId, $since);
        } catch (\Throwable $e) {
            return $entries; // Einträge nicht lesbar → Konto bleibt, wie es ist
        }
        $count = array_sum($byType);
        $due   = intdiv($count, $per);

        $cq = $this->con->prepare("SELECT COUNT(*) FROM lukaten_transaction WHERE manager_id = ? AND preview = ? AND source = 'entries'");
        $cq->execute([$managerId, $this->isLukatenPreview() ? 1 : 0]);
        $credited = (int) $cq->fetchColumn();
        for ($n = $credited + 1; $n <= $due; $n++) {
            if ($this->bookLukaten($managerId, 1.0, 'entries', "entries:$n", $seasonId)) $credited++;
        }

        $reached = max($credited, $due);
        return ['since' => $since, 'per' => $per, 'count' => $count, 'by_type' => $byType,
                'credited' => $credited, 'to_next' => ($reached + 1) * $per - $count];
    }

    private function sumLukaten(string $managerId): float
    {
        $q = $this->con->prepare("SELECT COALESCE(SUM(amount), 0) FROM lukaten_transaction WHERE manager_id = ? AND preview = ?");
        $q->execute([$managerId, $this->isLukatenPreview() ? 1 : 0]);
        return (float) $q->fetchColumn();
    }

    /**
     * Kontostand — in der Vorschau der des Vorschau-Kontos, sonst der echte. Mit $seasonId wird das Konto dabei
     * auf den Stand gebracht (Startbonus, Lukaten für Einträge), falls die Saison im Konto-Modus läuft.
     * Wirft ohne Migration.
     */
    public function getLukatenAccountBalance(string $managerId, ?string $seasonId = null): float
    {
        if ($seasonId !== null && $this->getSeasonLukatenMode($seasonId) === 'account') {
            $this->syncLukatenAccount($managerId, $seasonId);
        }
        return $this->sumLukaten($managerId);
    }

    /**
     * GET /lukaten — Stand für den eingeloggten Manager: mode = Lukaten-Modus der aktiven Saison für diesen Request,
     * preview_available/preview = Vorschau auf dieser Umgebung erlaubt / für diesen Request aktiv, ready = Kontobuch
     * vorhanden (Migration), balance = Kontostand im Konto-Modus (sonst null; bringt das Konto dabei auf den Stand).
     */
    public function getLukatenState(string $managerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $mode     = $this->getSeasonLukatenMode($seasonId);
        $ready    = $this->lukatenLedgerReady();

        $balance = null;
        if ($ready && $mode === 'account' && $seasonId !== null) {
            $balance = $this->getLukatenAccountBalance($managerId, $seasonId);
        }

        return [
            'mode'              => $mode,
            'preview_available' => $this->lukatenPreviewAvailable(),
            'preview'           => $this->isLukatenPreview(),
            'ready'             => $ready,
            'balance'           => $balance,
            'season_bonus'      => $this->lukatenAccountConfig()['season_bonus'],
        ];
    }

    /**
     * GET /lukaten/account — das Konto im Einzelnen (Seite /lukaten): Kontostand, Summen je Quelle, Stand der
     * Einträge (wie viele zählen, wie viele bis zur nächsten Lukate), die einzelnen Buchungen (ohne die vielen
     * Einzelbuchungen für Einträge — die stehen als Summe in totals) und die Regeln samt Preisen.
     * Außerhalb des Konto-Modus nur {mode, preview, ready} mit balance null.
     */
    public function getLukatenAccount(string $managerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $mode     = $this->getSeasonLukatenMode($seasonId);
        $ready    = $this->lukatenLedgerReady();
        $preview  = $this->isLukatenPreview();
        $base     = ['mode' => $mode, 'preview' => $preview, 'ready' => $ready, 'balance' => null];
        if (!$ready || $mode !== 'account' || $seasonId === null) return $base;

        $cfg     = $this->lukatenAccountConfig();
        $entries = $this->syncLukatenAccount($managerId, $seasonId);
        $flag    = $preview ? 1 : 0;

        $tq = $this->con->prepare(
            "SELECT source, SUM(amount) AS amount, COUNT(*) AS bookings FROM lukaten_transaction
             WHERE manager_id = ? AND preview = ? GROUP BY source"
        );
        $tq->execute([$managerId, $flag]);
        $totals = [];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $totals[$r['source']] = ['amount' => (float) $r['amount'], 'bookings' => (int) $r['bookings']];
        }

        $kinds  = $this->stickerPackKinds();
        $offers = $this->stickerShopOffers('account');
        $lq = $this->con->prepare(
            "SELECT lt.source, lt.source_key, lt.amount, lt.created_at, s.start_date AS season_start,
                    sp.pack_kind, c.name AS club_name
             FROM lukaten_transaction lt
             LEFT JOIN season s ON s.id = lt.season_id
             LEFT JOIN sticker_pack sp ON sp.id = lt.pack_id
             LEFT JOIN club c ON c.id = sp.club_id
             WHERE lt.manager_id = ? AND lt.preview = ? AND lt.source != 'entries'
             ORDER BY lt.created_at DESC LIMIT 50"
        );
        $lq->execute([$managerId, $flag]);
        $transactions = array_map(function ($r) use ($kinds, $offers) {
            // Pack-Art aus dem Pack bzw. — Vorschau-Käufe haben keins — aus dem Angebot im Buchungsschlüssel
            $kind = $r['pack_kind'] ?? ($offers[explode(':', (string) $r['source_key'])[1] ?? '']['kind'] ?? null);
            return [
                'source' => $r['source'], 'amount' => (float) $r['amount'], 'created_at' => $r['created_at'],
                'season_start' => $r['source'] === 'season_bonus' ? $r['season_start'] : null,
                'pack_name' => $r['source'] === 'pack' ? ($kinds[$kind]['name'] ?? null) : null,
                'club_name' => $r['club_name'],
            ];
        }, $lq->fetchAll(PDO::FETCH_ASSOC));

        return $base + [
            'balance'      => $this->sumLukaten($managerId),
            'totals'       => $totals,
            'entries'      => $entries,
            'transactions' => $transactions,
            'rules'        => [
                'season_bonus'       => $cfg['season_bonus'],
                'entries_per_lukate' => $cfg['entries_per_lukate'],
                'packs'              => array_values(array_map(
                    fn($o) => ['kind' => $o['kind'], 'name' => $o['name'], 'size' => $o['size'], 'price' => $o['price']], $offers)),
                'eur_bundles'        => array_map(fn($b) => ['amount_cents' => $b[0], 'lukaten' => $b[1]], $cfg['eur_bundles']),
            ],
        ];
    }

    /** Eigenes Vorschau-Konto leeren (alle als preview markierten Buchungen) — Anzahl gelöschter Zeilen. */
    public function resetLukatenPreview(string $managerId): int
    {
        $q = $this->con->prepare("DELETE FROM lukaten_transaction WHERE manager_id = ? AND preview = 1");
        $q->execute([$managerId]);
        return $q->rowCount();
    }
}
