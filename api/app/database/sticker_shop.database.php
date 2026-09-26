<?php

/**
 * "Die Klebrigsten" — Shop: Lukaten (Wettwährung aus dem Bestico) gegen Sticker-Packs.
 * Lukaten gibt es je Liga, das Album aber nur einmal je Manager — bezahlt wird deshalb immer aus der
 * Hauptliga des Managers: seiner obersten Liga mit Sticker-Album (Division mit dem niedrigsten level,
 * bei Gleichstand die zuerst beigetretene), unabhängig davon, in welcher Liga er gerade eingeloggt ist.
 */
trait StickerShopTrait
{
    /** Hauptliga des Managers {id, name, db_name} oder null (in keiner aktiven Liga mit Sticker-Album). */
    public function getStickerShopLeague(string $managerId): ?array
    {
        try {
            $q = $this->con->prepare(
                "SELECT l.id, l.name, l.db_name
                 FROM manager_league ml
                 JOIN league l ON l.id = ml.league_id
                 LEFT JOIN division d ON d.id = l.division_id
                 WHERE ml.manager_id = ? AND ml.status = 'active' AND l.sticker_enabled = 1
                 ORDER BY d.level IS NULL, d.level ASC, ml.joined_at ASC, l.name ASC
                 LIMIT 1"
            );
            $q->execute([$managerId]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return null; // Migration (sticker_enabled) fehlt
        }
    }

    /** Verbindung zur Liga-DB der Hauptliga — die bestehende, falls der Manager gerade dort eingeloggt ist. */
    private function stickerShopConnection(array $league): PDO
    {
        if ($this->con_league && ($GLOBALS['auth_league_id'] ?? null) === $league['id']) {
            return $this->con_league;
        }
        return $this->createConnection($_ENV['DB_HOST'], $league['db_name'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
    }

    /** GET /sticker/shop — Hauptliga + Lukaten-Guthaben dort (aktive Saison). */
    public function getStickerShop(string $managerId): array
    {
        $league   = $this->getStickerShopLeague($managerId);
        $seasonId = $this->getActiveSeasonId();
        if (!$league || !$seasonId) {
            return ['league' => $league ? ['id' => $league['id'], 'name' => $league['name']] : null, 'budget' => null];
        }
        $budget = $this->getManagerLukatenBudget($managerId, $seasonId, null, false, $this->stickerShopConnection($league));
        return ['league' => ['id' => $league['id'], 'name' => $league['name']], 'budget' => $budget];
    }
}
