<?php

trait PowerrankingTrait
{
    private function getMatchday1LockStatus(string $seasonId): array
    {
        $divisionId = $this->getLeagueDivisionId();
        if ($divisionId !== null) {
            $q = $this->con->prepare(
                "SELECT kickoff_date, (kickoff_date IS NOT NULL AND kickoff_date <= NOW()) AS locked
                 FROM matchday WHERE season_id = :sid AND division_id = :did AND number = 1 LIMIT 1"
            );
            $q->execute([':sid' => $seasonId, ':did' => $divisionId]);
        } else {
            $q = $this->con->prepare(
                "SELECT m.kickoff_date, (m.kickoff_date IS NOT NULL AND m.kickoff_date <= NOW()) AS locked
                 FROM matchday m JOIN division d ON d.id = m.division_id
                 WHERE m.season_id = :sid AND m.number = 1 AND d.level = 1 AND LOWER(d.country_id) = 'de'
                 LIMIT 1"
            );
            $q->execute([':sid' => $seasonId]);
        }
        $row = $q->fetch(PDO::FETCH_ASSOC);
        // Keine Spieltag-1-Zeile angelegt -> Tippphase gilt als offen (nicht gesperrt)
        return ['kickoff_date' => $row['kickoff_date'] ?? null, 'locked' => $row ? (bool) $row['locked'] : false];
    }

    /**
     * $preview (nur vom Controller für Admins gesetzt, siehe PowerrankingController::get()) zeigt
     * die Reveal-Ansicht (Tabelle + alle Tipps) bereits vor Anpfiff Spieltag 1 an — für Admins, die
     * während der laufenden Tippphase kontrollieren wollen, was Manager bisher abgegeben haben.
     * `locked` im Response bleibt der echte Sperrstatus; `preview` markiert zusätzlich, dass die
     * Reveal-Daten nur wegen des Admin-Previews gezeigt werden, obwohl noch nicht gesperrt ist.
     */
    public function getPowerrankingState(string $seasonId, string $managerId, bool $preview = false): array
    {
        if (!$this->isPowerrankingEnabled()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Powerranking ist für diese Liga deaktiviert'];
        }

        $status = $this->getMatchday1LockStatus($seasonId);

        if (!$status['locked'] && !$preview) {
            $q = $this->con_league->prepare(
                "SELECT team_id, position FROM powerranking_pick
                 WHERE season_id = :sid AND manager_id = :mid ORDER BY position ASC"
            );
            $q->execute([':sid' => $seasonId, ':mid' => $managerId]);

            $submittedQ = $this->con_league->prepare(
                "SELECT COUNT(DISTINCT manager_id) FROM powerranking_pick WHERE season_id = :sid"
            );
            $submittedQ->execute([':sid' => $seasonId]);

            $totalQ = $this->con_league->prepare("SELECT COUNT(*) FROM team WHERE season_id = :sid");
            $totalQ->execute([':sid' => $seasonId]);

            return [
                'locked' => false, 'season_id' => $seasonId, 'kickoff_date' => $status['kickoff_date'],
                'my_picks' => $q->fetchAll(PDO::FETCH_ASSOC),
                'submitted_count' => (int) $submittedQ->fetchColumn(),
                'total_managers' => (int) $totalQ->fetchColumn(),
            ];
        }

        // Reveal-Phase: reale aktuelle Tabelle + alle Tipps + Abweichungen
        // Standard-Wettkampf-Rang (1224): punktgleiche Teams (z.B. alle 0 Punkte vor Saisonstart)
        // teilen sich denselben Platz, statt per SQL-Zeilenreihenfolge willkürlich durchnummeriert
        // zu werden — sonst würden Tipper bei Punktgleichstand unfair unterschiedliche Diffs erhalten.
        $standingsRows = $this->getSeasonStandings($seasonId)['standings'];
        $actualPosByTeam = [];
        $standings = [];
        $prevPoints = null;
        $rank = 0;
        foreach ($standingsRows as $i => $row) {
            $points = (int) $row['total_points'];
            if ($prevPoints === null || $points !== $prevPoints) $rank = $i + 1;
            $prevPoints = $points;

            $actualPosByTeam[$row['team_id']] = $rank;
            $standings[] = [
                'team_id' => $row['team_id'], 'team_name' => $row['team_name'], 'color' => $row['color'],
                'manager_name' => $row['manager_name'], 'season_id' => $row['season_id'],
                'total_points' => $points, 'actual_position' => $rank,
            ];
        }

        $allQ = $this->con_league->prepare(
            "SELECT pp.manager_id, m.manager_name, m.alias, pp.team_id, pp.position
             FROM powerranking_pick pp JOIN manager m ON m.id = pp.manager_id
             WHERE pp.season_id = :sid ORDER BY m.manager_name ASC, pp.position ASC"
        );
        $allQ->execute([':sid' => $seasonId]);

        $byManager = [];
        foreach ($allQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid = $r['manager_id'];
            $byManager[$mid] ??= [
                'manager_id' => $mid, 'manager_name' => $r['manager_name'], 'alias' => $r['alias'],
                'total_deviation' => 0, 'picks' => [],
            ];
            $actual = $actualPosByTeam[$r['team_id']] ?? null;
            $dev = $actual !== null ? abs((int) $r['position'] - $actual) : null;
            if ($dev !== null) $byManager[$mid]['total_deviation'] += $dev;
            $byManager[$mid]['picks'][] = [
                'team_id' => $r['team_id'], 'predicted_position' => (int) $r['position'],
                'actual_position' => $actual, 'deviation' => $dev,
            ];
        }

        $entries = array_values($byManager);
        usort($entries, fn($a, $b) =>
            $a['total_deviation'] <=> $b['total_deviation'] ?: strcmp($a['manager_name'], $b['manager_name']));

        return [
            'locked' => $status['locked'], 'preview' => !$status['locked'] && $preview,
            'season_id' => $seasonId, 'kickoff_date' => $status['kickoff_date'],
            'standings' => $standings, 'entries' => $entries,
        ];
    }

    /**
     * Ersetzt alle Picks eines Managers per DELETE+INSERT (kein UPDATE-in-place wie bei
     * team_lineup, da hier vor dem ersten Tipp noch keine Zeilen existieren) — gleiches Muster wie
     * H2HTrait::setGroupTeams() für h2h_group_team (DELETE-then-loop-INSERT).
     */
    public function replacePowerrankingPicks(string $seasonId, string $managerId, array $picks): array
    {
        if (!$this->isPowerrankingEnabled()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Powerranking ist für diese Liga deaktiviert'];
        }

        if ($this->getMatchday1LockStatus($seasonId)['locked']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippphase beendet — Spieltag 1 hat bereits angepfiffen'];
        }

        $teamsQ = $this->con_league->prepare("SELECT id FROM team WHERE season_id = :sid");
        $teamsQ->execute([':sid' => $seasonId]);
        $teamIds = $teamsQ->fetchAll(PDO::FETCH_COLUMN);
        if (empty($teamIds)) {
            http_response_code(422);
            return ['status' => false, 'message' => 'Keine Teams in dieser Saison'];
        }

        $expected = $teamIds; sort($expected);
        $picked = array_column($picks, 'team_id'); sort($picked);
        if ($expected !== $picked) {
            http_response_code(422);
            return ['status' => false, 'message' => 'picks muss genau alle Teams der Saison je einmal enthalten'];
        }

        $positions = array_map('intval', array_column($picks, 'position')); sort($positions);
        if ($positions !== range(1, count($teamIds))) {
            http_response_code(422);
            return ['status' => false, 'message' => 'position muss 1..N ohne Lücken oder Duplikate sein'];
        }

        $this->con_league->prepare(
            "DELETE FROM powerranking_pick WHERE season_id = :sid AND manager_id = :mid"
        )->execute([':sid' => $seasonId, ':mid' => $managerId]);

        $insert = $this->con_league->prepare(
            "INSERT INTO powerranking_pick (id, season_id, manager_id, team_id, position)
             VALUES (UUID(), :sid, :mid, :tid, :pos)"
        );
        foreach ($picks as $p) {
            $insert->execute([':sid' => $seasonId, ':mid' => $managerId, ':tid' => $p['team_id'], ':pos' => (int) $p['position']]);
        }

        return ['status' => true];
    }
}
