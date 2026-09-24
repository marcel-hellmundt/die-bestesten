<?php

trait StickerTrait
{
    /** Stichtag des Sticker-Albums: 1. September des Startjahres der Saison. */
    private function stickerCutoffDate(string $seasonId): ?string
    {
        $q = $this->con->prepare("SELECT CONCAT(YEAR(start_date), '-09-01') FROM season WHERE id = ?");
        $q->execute([$seasonId]);
        return $q->fetchColumn() ?: null;
    }

    /**
     * "Die Klebrigsten" V0 — Album-Vorschau für die Parameter-Simulation: alle Spieler, die am
     * Stichtag (1.9.) laut player_in_club bei einem Verein der 1. Bundesliga (level=1, country=DE)
     * der aktiven Saison waren, gruppiert nach Verein (Reihenfolge wie /noten: Vorsaison-Tabellenplatz).
     * Dubletten (überlappende Vereins-Stints am Stichtag, z.B. Wechsel exakt am 1.9.) werden auf den
     * jüngsten Stint (größtes from_date) reduziert — jeder Spieler kommt genau einmal vor.
     * Nur Spieler mit Foto (player_in_season.photo_uploaded der Bundesliga-Zeile) bekommen einen Sticker.
     * price = player_in_season.price der Bundesliga-Zeile; Platzhalter-Marktwerte > 50 Mio
     * (POST /player/create_manual setzt 99 Mio) und fehlende Werte → null (Frontend: wie Minimum).
     * matchdays = alle Spieltage der Saison (Division 1. Bundesliga) für die Zeitachse der Simulation.
     */
    public function getStickerAlbumPreview(): array
    {
        $empty = ['season_id' => null, 'cutoff_date' => null, 'matchdays' => [], 'clubs' => []];
        $divisionId = $this->getBundesligaDivisionId();
        $seasonId   = $this->getActiveSeasonId();
        if ($divisionId === null || $seasonId === null) return $empty;
        $cutoff = $this->stickerCutoffDate($seasonId);

        $prevStmt = $this->con->prepare(
            "SELECT id FROM season WHERE start_date < (SELECT start_date FROM season WHERE id = ?)
             ORDER BY start_date DESC LIMIT 1"
        );
        $prevStmt->execute([$seasonId]);
        $prevSeasonId = $prevStmt->fetchColumn() ?: null;

        $clubQuery = $this->con->prepare(
            "SELECT c.id, c.name, c.short_name, c.logo_uploaded
             FROM club_in_season cis
             JOIN club c ON c.id = cis.club_id
             LEFT JOIN club_in_season cis_prev
                 ON cis_prev.club_id = cis.club_id AND cis_prev.season_id = ?
             WHERE cis.season_id = ? AND cis.division_id = ?
             ORDER BY cis_prev.position IS NULL, cis_prev.position ASC, c.name ASC"
        );
        $clubQuery->execute([$prevSeasonId, $seasonId, $divisionId]);
        $clubs = [];
        foreach ($clubQuery->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $clubs[$c['id']] = [
                'id'            => $c['id'],
                'name'          => $c['name'],
                'short_name'    => $c['short_name'],
                'logo_uploaded' => (bool) $c['logo_uploaded'],
                'players'       => [],
            ];
        }

        $pq = $this->con->prepare(
            "SELECT p.id, p.displayname, p.first_name, p.last_name, pic.club_id, pis.position, pis.price, pis.photo_uploaded
             FROM player_in_club pic
             JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = :season AND cis.division_id = :division
             JOIN player p ON p.id = pic.player_id
             LEFT JOIN player_in_season pis
                 ON pis.player_id = pic.player_id AND pis.season_id = :season2 AND pis.division_id = :division2
             WHERE pic.from_date <= :cutoff AND (pic.to_date IS NULL OR pic.to_date >= :cutoff2)
             ORDER BY pic.from_date DESC"
        );
        $pq->execute([
            ':season' => $seasonId, ':division' => $divisionId,
            ':season2' => $seasonId, ':division2' => $divisionId,
            ':cutoff' => $cutoff, ':cutoff2' => $cutoff,
        ]);

        $seen = [];
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($seen[$r['id']]) || !isset($clubs[$r['club_id']])) continue;
            $seen[$r['id']] = true;
            // Nur Spieler mit Foto bekommen einen Sticker (photo_uploaded der Bundesliga-Zeile dieser Saison)
            if (!$r['photo_uploaded']) continue;
            $price = $r['price'] !== null ? (int) $r['price'] : null;
            $clubs[$r['club_id']]['players'][] = [
                'id'             => $r['id'],
                'displayname'    => $r['displayname'],
                'first_name'     => $r['first_name'],
                'last_name'      => $r['last_name'],
                'position'       => $r['position'],
                'price'          => $price !== null && $price > 0 && $price <= 50000000 ? $price : null,
                'photo_uploaded' => (bool) $r['photo_uploaded'],
            ];
        }

        $posOrder = ['GOALKEEPER' => 0, 'DEFENDER' => 1, 'MIDFIELDER' => 2, 'FORWARD' => 3];
        foreach ($clubs as &$c) {
            usort($c['players'], fn($a, $b) =>
                (($posOrder[$a['position']] ?? 9) <=> ($posOrder[$b['position']] ?? 9))
                ?: (($b['price'] ?? 0) <=> ($a['price'] ?? 0))
            );
        }
        unset($c);

        $mdq = $this->con->prepare(
            "SELECT number, kickoff_date FROM matchday
             WHERE season_id = ? AND division_id = ? AND kickoff_date IS NOT NULL
             ORDER BY number ASC"
        );
        $mdq->execute([$seasonId, $divisionId]);

        return [
            'season_id'   => $seasonId,
            'cutoff_date' => $cutoff,
            'matchdays'   => array_map(fn($m) => ['number' => (int) $m['number'], 'kickoff_date' => $m['kickoff_date']], $mdq->fetchAll(PDO::FETCH_ASSOC)),
            'clubs'       => array_values(array_filter($clubs, fn($c) => count($c['players']) > 0)),
        ];
    }
}
