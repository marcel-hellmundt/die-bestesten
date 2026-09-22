<?php

trait NotenTrait
{
    /**
     * Anonymes Aufruf-Tracking für die Gast-Seite /noten (siehe noten_guest_visit im Schema für die
     * Consent-Begründung). $anonId kommt vom Client NUR nach Zustimmung im Consent-Banner
     * (localStorage) — ohne Zustimmung wird ohne ID getrackt (reiner, nicht verknüpfbarer
     * Seitenaufruf-Zähler, kein Wiedererkennen möglich). Gleiches Heartbeat-Muster wie
     * SessionTrait::touchSession(), aber ohne Named-Lock: eine doppelt gezählte 0s-Zeile bei
     * parallelen Requests ist hier unkritisch (nur eine grobe Besucherzahl, kein individuelles
     * Nutzerkonto betroffen).
     */
    public function trackNotenGuestVisit(?string $anonId): void
    {
        [$deviceType, $os, $browser] = $this->parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');

        if ($anonId === null) {
            // Kein Consent — immer eine neue Zeile, da ohne ID keine Zuordnung zu früheren
            // Aufrufen desselben Besuchers möglich (und gewollt) ist.
            $this->con->prepare(
                "INSERT INTO noten_guest_visit (anon_id, device_type, os, browser)
                 VALUES (NULL, :device_type, :os, :browser)"
            )->execute([':device_type' => $deviceType, ':os' => $os, ':browser' => $browser]);
            return;
        }

        $find = $this->con->prepare(
            "SELECT id, device_type, os, browser FROM noten_guest_visit
             WHERE anon_id = :id AND ended_at >= (NOW() - INTERVAL 2 MINUTE)
             ORDER BY ended_at DESC LIMIT 1"
        );
        $find->execute([':id' => $anonId]);
        $open = $find->fetch(PDO::FETCH_ASSOC);

        $sameDevice = $open
            && $open['device_type'] === $deviceType
            && $open['os'] === $os
            && $open['browser'] === $browser;

        if ($sameDevice) {
            $this->con->prepare(
                "UPDATE noten_guest_visit SET ended_at = NOW() WHERE id = :id"
            )->execute([':id' => $open['id']]);
        } else {
            $this->con->prepare(
                "INSERT INTO noten_guest_visit (anon_id, device_type, os, browser)
                 VALUES (:id, :device_type, :os, :browser)"
            )->execute([':id' => $anonId, ':device_type' => $deviceType, ':os' => $os, ':browser' => $browser]);
        }
    }

    /**
     * Öffentliche Noten-Übersicht (/noten) — 1. Bundesliga (level=1, country=DE), fest, unabhängig
     * von einer evtl. konfigurierten Liga-Division, da die Seite auch Gästen ohne Liga-Kontext
     * dient. $matchdayId optional — Default: letzter bereits angepfiffener Spieltag der aktiven
     * Saison. Clubs sortiert nach Vorsaison-Tabellenplatz (club_in_season.position), Spieler je
     * Club nur mit participation starting/substitute für den gewählten Spieltag.
     */
    public function getNotenOverview(?string $matchdayId): array
    {
        $divisionId = $this->getBundesligaDivisionId();
        if ($divisionId === null) {
            return ['season_id' => null, 'matchdays' => [], 'matchday' => null, 'clubs' => []];
        }

        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null) {
            return ['season_id' => null, 'matchdays' => [], 'matchday' => null, 'clubs' => []];
        }

        // Nur bereits angepfiffene Spieltage — vor Anpfiff existieren ohnehin nie player_rating-
        // Zeilen (siehe POST /player_rating/init), gleicher Filter wie das Frontend bisher lokal
        // anwendete (ratings.component.ts).
        $mdq = $this->con->prepare(
            "SELECT id, number, start_date, kickoff_date FROM matchday
             WHERE season_id = :season_id AND division_id = :division_id
               AND kickoff_date IS NOT NULL AND kickoff_date <= NOW()
             ORDER BY number ASC"
        );
        $mdq->execute([':season_id' => $seasonId, ':division_id' => $divisionId]);
        $matchdays = $mdq->fetchAll(PDO::FETCH_ASSOC);

        $matchday = null;
        if ($matchdayId !== null) {
            foreach ($matchdays as $m) {
                if ($m['id'] === $matchdayId) { $matchday = $m; break; }
            }
        } else {
            // Letzter angepfiffener Spieltag = größte number (Liste bereits auf kickoff_date <=
            // NOW() eingegrenzt, also einfach das letzte Element).
            $matchday = end($matchdays) ?: null;
        }

        if ($matchday === null) {
            return ['season_id' => $seasonId, 'matchdays' => $matchdays, 'matchday' => null, 'clubs' => []];
        }

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
                 ON cis_prev.club_id = cis.club_id
                AND cis_prev.season_id = ?
                AND cis_prev.division_id = cis.division_id
             WHERE cis.season_id = ? AND cis.division_id = ?
             ORDER BY cis_prev.position IS NULL, cis_prev.position ASC, c.name ASC"
        );
        $clubQuery->execute([$prevSeasonId, $seasonId, $divisionId]);
        $clubs = $clubQuery->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clubs as &$c) { $c['players'] = []; }
        unset($c);

        $clubIndexById = [];
        foreach ($clubs as $i => $c) { $clubIndexById[$c['id']] = $i; }

        // Bewusst KEIN pis.photo_uploaded/Foto-URL — Spielerbilder dürfen auf dieser öffentlichen,
        // nicht-eingeloggten Seite unter keinen Umständen ausgespielt werden.
        $playerQuery = $this->con->prepare(
            "SELECT p.id, p.displayname, pis.position,
                    pr.club_id, pr.grade, pr.points, pr.participation,
                    pr.sds, pr.goals, pr.assists, pr.clean_sheet, pr.red_card, pr.yellow_red_card
             FROM player_rating pr
             JOIN player p ON p.id = pr.player_id
             JOIN club_in_season cis ON cis.club_id = pr.club_id AND cis.season_id = ? AND cis.division_id = ?
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ? AND pis.division_id = ?
             WHERE pr.matchday_id = ? AND pr.participation IN ('starting', 'substitute')
             ORDER BY FIELD(pr.participation, 'starting', 'substitute'),
                      FIELD(pis.position, 'GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD'),
                      pis.price DESC"
        );
        $playerQuery->execute([$seasonId, $divisionId, $seasonId, $divisionId, $matchday['id']]);

        // Eigener Kader nur für eingeloggte Manager (Guard::authorize() dekodiert auf diesem
        // Guest-Endpunkt ein evtl. mitgeschicktes Token optional, siehe guard.php) — markiert die
        // eigenen Spieler im Frontend hervorgehoben. Gäste ohne Token bekommen hierfür nie Daten,
        // $ownPlayerIds bleibt leer.
        $ownPlayerIds = $this->getOwnSquadPlayerIds($seasonId);

        foreach ($playerQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idx = $clubIndexById[$row['club_id']] ?? null;
            if ($idx === null) continue;
            $clubs[$idx]['players'][] = [
                'id'              => $row['id'],
                'displayname'     => $row['displayname'],
                'position'        => $row['position'],
                'grade'           => $row['grade'] !== null ? (float) $row['grade'] : null,
                'points'          => (int) $row['points'],
                'participation'   => $row['participation'],
                'own'             => isset($ownPlayerIds[$row['id']]),
                'sds'             => (bool) $row['sds'],
                'goals'           => (int) $row['goals'],
                'assists'         => (int) $row['assists'],
                'clean_sheet'     => (bool) $row['clean_sheet'],
                'red_card'        => (bool) $row['red_card'],
                'yellow_red_card' => (bool) $row['yellow_red_card'],
            ];
        }

        return [
            'season_id' => $seasonId,
            'matchdays' => $matchdays,
            'matchday'  => $matchday,
            'clubs'     => $clubs,
        ];
    }

    private function getBundesligaDivisionId(): ?string
    {
        $q = $this->con->prepare(
            "SELECT id FROM division WHERE level = 1 AND LOWER(country_id) = 'de' LIMIT 1"
        );
        $q->execute();
        return $q->fetchColumn() ?: null;
    }

    /** player_id => true für den aktiven Kader des eingeloggten Managers in $seasonId (leer für Gäste). */
    private function getOwnSquadPlayerIds(string $seasonId): array
    {
        $managerId = $GLOBALS['auth_manager_id'] ?? null;
        if (!$managerId) return [];

        try {
            $tq = $this->con_league->prepare(
                "SELECT id FROM team WHERE manager_id = ? AND season_id = ? LIMIT 1"
            );
            $tq->execute([$managerId, $seasonId]);
            $teamId = $tq->fetchColumn();
            if (!$teamId) return [];

            $pq = $this->con_league->prepare(
                "SELECT player_id FROM player_in_team WHERE team_id = ? AND to_matchday_id IS NULL"
            );
            $pq->execute([$teamId]);
            return array_flip($pq->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException) {
            // Kein con_league verbunden (z.B. Manager ohne aktive Liga) — einfach ohne
            // Hervorhebung fortfahren statt den ganzen Request scheitern zu lassen.
            return [];
        }
    }
}
