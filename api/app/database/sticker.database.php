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
     * Bundesliga-Vereine der Saison in Album-Reihenfolge: Vorsaison-Tabellenplatz, Liga-Level der
     * Vorsaison zuerst (Aufsteiger hinter allen Vorjahres-Bundesligisten), unplatzierte ans Ende.
     * Die Reihenfolge bestimmt auch die Seltenheit der Vereins-Sticker (stickerClubPrice()).
     */
    private function stickerClubRows(string $seasonId, string $divisionId): array
    {
        $prevStmt = $this->con->prepare(
            "SELECT id FROM season WHERE start_date < (SELECT start_date FROM season WHERE id = ?)
             ORDER BY start_date DESC LIMIT 1"
        );
        $prevStmt->execute([$seasonId]);
        $prevSeasonId = $prevStmt->fetchColumn() ?: null;

        $clubQuery = $this->con->prepare(
            "SELECT c.id, c.name, c.short_name, c.logo_uploaded, c.primary_color, c.secondary_color,
                    COALESCE(s.name, s.official_name) AS stadium_name
             FROM club_in_season cis
             JOIN club c ON c.id = cis.club_id
             LEFT JOIN club_stadium cst ON cst.club_id = c.id AND cst.to_date IS NULL
             LEFT JOIN stadium s ON s.id = cst.stadium_id
             LEFT JOIN club_in_season cis_prev
                 ON cis_prev.club_id = cis.club_id AND cis_prev.season_id = ?
             LEFT JOIN division d_prev ON d_prev.id = cis_prev.division_id
             WHERE cis.season_id = ? AND cis.division_id = ?
             ORDER BY cis_prev.position IS NULL, d_prev.level ASC, cis_prev.position ASC, c.name ASC"
        );
        $clubQuery->execute([$prevSeasonId, $seasonId, $divisionId]);
        $clubs = [];
        foreach ($clubQuery->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $clubs[$c['id']] = [
                'id'              => $c['id'],
                'name'            => $c['name'],
                'short_name'      => $c['short_name'],
                'logo_uploaded'   => (bool) $c['logo_uploaded'],
                'primary_color'   => $c['primary_color'],
                'secondary_color' => $c['secondary_color'],
                'stadium_name'    => $c['stadium_name'],
                'players'         => [],
            ];
        }
        return $clubs;
    }

    /** Alle Spieltage der Saison (Division 1. Bundesliga) — Zeitachse der Simulation. */
    private function stickerMatchdays(string $seasonId, string $divisionId): array
    {
        $mdq = $this->con->prepare(
            "SELECT number, kickoff_date FROM matchday
             WHERE season_id = ? AND division_id = ? AND kickoff_date IS NOT NULL
             ORDER BY number ASC"
        );
        $mdq->execute([$seasonId, $divisionId]);
        return array_map(fn($m) => ['number' => (int) $m['number'], 'kickoff_date' => $m['kickoff_date']], $mdq->fetchAll(PDO::FETCH_ASSOC));
    }

    private function sortStickerPlayers(array &$clubs): void
    {
        $posOrder = ['GOALKEEPER' => 0, 'DEFENDER' => 1, 'MIDFIELDER' => 2, 'FORWARD' => 3];
        foreach ($clubs as &$c) {
            usort($c['players'], fn($a, $b) =>
                (($posOrder[$a['position']] ?? 9) <=> ($posOrder[$b['position']] ?? 9))
                ?: (($b['price'] ?? 0) <=> ($a['price'] ?? 0))
            );
        }
        unset($c);
    }

    /**
     * Gewichtungs-Marktwert der Vereins-Sticker (Wappen/Stadion) nach Album-Reihenfolge: Erster wie
     * 3 Mio (etwas seltener), Letzter wie 0,5 Mio, linear dazwischen — muss zu clubStickerPrice()
     * im Frontend (album.model.ts) passen.
     */
    private function stickerClubPrice(int $rank, int $clubCount): int
    {
        if ($clubCount <= 1) return 3_000_000;
        $t = min(max($rank / ($clubCount - 1), 0), 1);
        return (int) round(3_000_000 - $t * 2_500_000);
    }

    /**
     * "Die Klebrigsten" — Album-Vorschau (live berechnet) für die Parameter-Simulation und als Grundlage
     * für das Einfrieren (syncStickerAlbum()): alle Spieler, die am Stichtag (1.9.) laut player_in_club
     * bei einem Verein der 1. Bundesliga (level=1, country=DE) der aktiven Saison waren, gruppiert nach
     * Verein. Dubletten (überlappende Vereins-Stints am Stichtag, z.B. Wechsel exakt am 1.9.) werden auf
     * den jüngsten Stint (größtes from_date) reduziert — jeder Spieler kommt genau einmal vor.
     * Nur Spieler mit Foto (player_in_season.photo_uploaded der Bundesliga-Zeile) bekommen einen Sticker.
     * price = player_in_season.price der Bundesliga-Zeile; Platzhalter-Marktwerte > 50 Mio
     * (POST /player/create_manual setzt 99 Mio) und fehlende Werte → null (Frontend: wie Minimum).
     */
    public function getStickerAlbumPreview(): array
    {
        $empty = ['season_id' => null, 'cutoff_date' => null, 'matchdays' => [], 'clubs' => []];
        $divisionId = $this->getBundesligaDivisionId();
        $seasonId   = $this->getActiveSeasonId();
        if ($divisionId === null || $seasonId === null) return $empty;
        $cutoff = $this->stickerCutoffDate($seasonId);
        $clubs  = $this->stickerClubRows($seasonId, $divisionId);

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
        $this->sortStickerPlayers($clubs);

        return [
            'season_id'   => $seasonId,
            'cutoff_date' => $cutoff,
            'matchdays'   => $this->stickerMatchdays($seasonId, $divisionId),
            'clubs'       => array_values(array_filter($clubs, fn($c) => count($c['players']) > 0)),
        ];
    }

    /**
     * Friert das Album der aktiven Saison ein (Tabelle sticker) bzw. ergänzt es: fehlende Spieler
     * (z.B. Foto erst später hochgeladen) und Vereins-Sticker werden hinzugefügt, bestehende Sticker
     * nie geändert oder gelöscht — Seltenheit (price) bleibt stabil, gesammelte Karten bleiben gültig.
     */
    public function syncStickerAlbum(): array
    {
        $preview = $this->getStickerAlbumPreview();
        $seasonId = $preview['season_id'];
        if ($seasonId === null) return ['season_id' => null, 'added' => 0, 'total' => 0];

        $minPrice = $this->stickerConfig()['min_price'];
        $ins = $this->con->prepare(
            "INSERT IGNORE INTO sticker (season_id, sticker_key, kind, club_id, player_id, position, price)
             VALUES (:season, :key, :kind, :club, :player, :position, :price)"
        );
        $added = 0;
        $clubCount = count($preview['clubs']);
        foreach ($preview['clubs'] as $rank => $club) {
            $clubPrice = $this->stickerClubPrice($rank, $clubCount);
            $rows = [
                ['key' => "{$club['id']}-logo",    'kind' => 'logo',    'player' => null, 'position' => null, 'price' => $clubPrice],
                ['key' => "{$club['id']}-stadium", 'kind' => 'stadium', 'player' => null, 'position' => null, 'price' => $clubPrice],
            ];
            foreach ($club['players'] as $p) {
                $rows[] = ['key' => $p['id'], 'kind' => 'player', 'player' => $p['id'], 'position' => $p['position'], 'price' => max($p['price'] ?? $minPrice, $minPrice)];
            }
            foreach ($rows as $r) {
                $ins->execute([
                    ':season' => $seasonId, ':key' => $r['key'], ':kind' => $r['kind'], ':club' => $club['id'],
                    ':player' => $r['player'], ':position' => $r['position'], ':price' => $r['price'],
                ]);
                $added += $ins->rowCount();
            }
        }

        $total = $this->con->prepare("SELECT COUNT(*) FROM sticker WHERE season_id = ?");
        $total->execute([$seasonId]);
        return ['season_id' => $seasonId, 'added' => $added, 'total' => (int) $total->fetchColumn()];
    }

    /**
     * Eingefrorenes Album der aktiven Saison (Tabelle sticker) im selben Format wie
     * getStickerAlbumPreview(); price = eingefrorener Gewichtungs-Marktwert. clubs=[] solange das
     * Album noch nicht erstellt wurde (POST /sticker/album/sync).
     */
    public function getStickerAlbum(): array
    {
        $empty = ['season_id' => null, 'cutoff_date' => null, 'matchdays' => [], 'clubs' => []];
        $divisionId = $this->getBundesligaDivisionId();
        $seasonId   = $this->getActiveSeasonId();
        if ($divisionId === null || $seasonId === null) return $empty;

        try {
            $sq = $this->con->prepare(
                "SELECT s.kind, s.club_id, s.player_id, s.position, s.price,
                        p.displayname, p.first_name, p.last_name
                 FROM sticker s LEFT JOIN player p ON p.id = s.player_id
                 WHERE s.season_id = ?"
            );
            $sq->execute([$seasonId]);
            $rows = $sq->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = []; // Migration noch nicht eingespielt
        }

        $ordered = $this->stickerClubRows($seasonId, $divisionId);
        $clubIds = array_unique(array_column($rows, 'club_id'));
        // Vereine, die inzwischen nicht mehr in der Bundesliga-Division geführt werden, trotzdem zeigen (hinten)
        $missing = array_diff($clubIds, array_keys($ordered));
        if ($missing) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $mq = $this->con->prepare("SELECT id, name, short_name, logo_uploaded, primary_color, secondary_color FROM club WHERE id IN ($in) ORDER BY name");
            $mq->execute(array_values($missing));
            foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ordered[$c['id']] = $c + ['stadium_name' => null, 'players' => []];
                $ordered[$c['id']]['logo_uploaded'] = (bool) $c['logo_uploaded'];
            }
        }
        $clubs = array_intersect_key($ordered, array_flip($clubIds));

        foreach ($rows as $r) {
            if (!isset($clubs[$r['club_id']])) continue;
            if ($r['kind'] !== 'player') {
                // Eingefrorener Gewichtungs-Marktwert der Vereins-Sticker (Wappen + Stadion identisch)
                $clubs[$r['club_id']]['sticker_price'] = (int) $r['price'];
                continue;
            }
            $clubs[$r['club_id']]['players'][] = [
                'id'             => $r['player_id'],
                'displayname'    => $r['displayname'],
                'first_name'     => $r['first_name'],
                'last_name'      => $r['last_name'],
                'position'       => $r['position'],
                'price'          => (int) $r['price'],
                'photo_uploaded' => true,
            ];
        }
        $this->sortStickerPlayers($clubs);

        return [
            'season_id'   => $seasonId,
            'cutoff_date' => $this->stickerCutoffDate($seasonId),
            'matchdays'   => $this->stickerMatchdays($seasonId, $divisionId),
            'clubs'       => array_values($clubs),
        ];
    }
}
