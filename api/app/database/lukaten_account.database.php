<?php

/**
 * Lukaten-Konto — neuer Modus ab der nächsten Saison (Konzept: docs/lukaten-economy-concept.md).
 * Ein Konto je Manager, unabhängig von Liga und Saison: jede Bewegung ist eine Zeile in lukaten_transaction
 * (globale DB), der Kontostand ist die Summe. Ob eine Saison so läuft, steht in season.lukaten_mode:
 *   classic — bisheriger Rechenweg je Liga und Saison (H2HPredictionTrait::getManagerLukatenBudget())
 *   account — dieses Konto
 * Stufe 1: Startbonus und Pack-Käufe im Shop. Tippen rechnet in beiden Modi noch klassisch.
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
            'season_bonus' => 20, // Startbonus je Saison, beim ersten Kontoabruf der Saison gebucht
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
     * Kontostand — in der Vorschau der des Vorschau-Kontos, sonst der echte. Mit $seasonId wird dabei der
     * Startbonus dieser Saison gebucht, falls sie im Konto-Modus läuft und er noch fehlt (wie das Tages-Pack:
     * beim ersten Abruf). Wirft ohne Migration.
     */
    public function getLukatenAccountBalance(string $managerId, ?string $seasonId = null): float
    {
        if ($seasonId !== null && $this->getSeasonLukatenMode($seasonId) === 'account') {
            $this->bookLukaten($managerId, (float) $this->lukatenAccountConfig()['season_bonus'], 'season_bonus', "season:$seasonId", $seasonId);
        }
        $q = $this->con->prepare("SELECT COALESCE(SUM(amount), 0) FROM lukaten_transaction WHERE manager_id = ? AND preview = ?");
        $q->execute([$managerId, $this->isLukatenPreview() ? 1 : 0]);
        return (float) $q->fetchColumn();
    }

    /**
     * GET /lukaten — Stand für den eingeloggten Manager: mode = Lukaten-Modus der aktiven Saison für diesen Request,
     * preview_available/preview = Vorschau auf dieser Umgebung erlaubt / für diesen Request aktiv, ready = Kontobuch
     * vorhanden (Migration), balance = Kontostand im Konto-Modus (sonst null; bucht dabei den Startbonus).
     */
    public function getLukatenState(string $managerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $mode     = $this->getSeasonLukatenMode($seasonId);

        try {
            $this->con->query("SELECT 1 FROM lukaten_transaction LIMIT 1");
            $ready = true;
        } catch (\Throwable $e) {
            $ready = false;
        }

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

    /** Eigenes Vorschau-Konto leeren (alle als preview markierten Buchungen) — Anzahl gelöschter Zeilen. */
    public function resetLukatenPreview(string $managerId): int
    {
        $q = $this->con->prepare("DELETE FROM lukaten_transaction WHERE manager_id = ? AND preview = 1");
        $q->execute([$managerId]);
        return $q->rowCount();
    }
}
