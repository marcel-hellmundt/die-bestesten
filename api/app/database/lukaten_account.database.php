<?php

/**
 * Lukaten-Konto — neuer Modus ab der nächsten Saison (Konzept: docs/lukaten-economy-concept.md).
 * Ein Konto je Manager, unabhängig von Liga und Saison: jede Bewegung ist eine Zeile in lukaten_transaction
 * (globale DB), der Kontostand ist die Summe. Ob eine Saison so läuft, steht in season.lukaten_mode:
 *   classic — bisheriger Rechenweg je Liga und Saison (H2HPredictionTrait::getManagerLukatenBudget())
 *   account — dieses Konto
 * Stufe 1: Startbonus und Pack-Käufe im Shop. Tippen rechnet in beiden Modi noch klassisch.
 * Ohne Migration 2026-10-07_lukaten_account.sql ist jede Saison classic.
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

    /** Modus einer Saison — classic, solange nichts anderes gesetzt ist oder die Migration fehlt. */
    public function getSeasonLukatenMode(?string $seasonId): string
    {
        if ($seasonId === null) return 'classic';
        try {
            $q = $this->con->prepare("SELECT lukaten_mode FROM season WHERE id = ?");
            $q->execute([$seasonId]);
            return $q->fetchColumn() === 'account' ? 'account' : 'classic';
        } catch (\Throwable $e) {
            return 'classic';
        }
    }

    /** Modus einer Saison setzen — false, wenn die Saison fehlt; wirft ohne Migration. */
    public function setSeasonLukatenMode(string $seasonId, string $mode): bool
    {
        $q = $this->con->prepare("UPDATE season SET lukaten_mode = ? WHERE id = ?");
        $q->execute([$mode, $seasonId]);
        if ($q->rowCount() > 0) return true;
        // rowCount ist auch 0, wenn der Modus schon so gesetzt war
        $e = $this->con->prepare("SELECT 1 FROM season WHERE id = ?");
        $e->execute([$seasonId]);
        return (bool) $e->fetchColumn();
    }

    /**
     * Eine Bewegung buchen (+ Gutschrift, − Ausgabe). Der Schlüssel ist je Manager eindeutig: ein zweiter Versuch
     * mit demselben Schlüssel bucht nichts — true, wenn neu gebucht wurde.
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

    /**
     * Kontostand. Mit $seasonId wird dabei der Startbonus dieser Saison gebucht, falls sie im Konto-Modus läuft
     * und er noch fehlt (wie das Tages-Pack: beim ersten Abruf). Wirft ohne Migration.
     */
    public function getLukatenAccountBalance(string $managerId, ?string $seasonId = null): float
    {
        if ($seasonId !== null && $this->getSeasonLukatenMode($seasonId) === 'account') {
            $this->bookLukaten($managerId, (float) $this->lukatenAccountConfig()['season_bonus'], 'season_bonus', "season:$seasonId", $seasonId);
        }
        $q = $this->con->prepare("SELECT COALESCE(SUM(amount), 0) FROM lukaten_transaction WHERE manager_id = ?");
        $q->execute([$managerId]);
        return (float) $q->fetchColumn();
    }
}
