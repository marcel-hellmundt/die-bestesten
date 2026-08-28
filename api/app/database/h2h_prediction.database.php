<?php

trait H2HPredictionTrait
{
    /**
     * Tipp-Status für ein H2H-Match, eingebettet in H2HTrait::getH2HMatchDetail(). Vor Anpfiff der
     * Matchday (Analog zu PowerrankingTrait::getMatchday1LockStatus) nur der eigene Tipp — die
     * Tipps anderer Manager bleiben geheim, bis der Anpfiff sie sperrt; danach alle Tipps offen.
     * $matchday kommt bereits geladen vom Aufrufer (getH2HMatchDetail hat es ohnehin schon per
     * matchday_id nachgeschlagen), um hier keine zweite Abfrage für den Sperrstatus zu brauchen.
     *
     * $preview (nur vom Controller für Admins gesetzt, siehe H2HController::get()) liefert die
     * Auswertung testweise schon vor Anpfiff mit — unter dem separaten Feld preview_entries statt
     * entries, damit locked für alle anderen (nicht-preview) Aufrufer korrekt false bleibt und
     * normale Manager davon nichts sehen; my_pick bleibt parallel verfügbar, der Admin kann also
     * weiterhin normal tippen, während er sich die Vorschau ansieht.
     */
    public function getH2HPredictionState(string $matchId, string $managerId, ?array $matchday, string $seasonId, bool $preview = false): array
    {
        $kickoffDate = $matchday['kickoff_date'] ?? null;
        $locked      = $kickoffDate !== null && strtotime($kickoffDate) <= time();

        $result = ['locked' => $locked, 'kickoff_date' => $kickoffDate];

        if (!$locked) {
            $mine = $this->con_league->prepare(
                "SELECT pick FROM h2h_prediction WHERE match_id = :mid AND manager_id = :man LIMIT 1"
            );
            $mine->execute([':mid' => $matchId, ':man' => $managerId]);
            $result['my_pick'] = $mine->fetchColumn() ?: null;
            // Tippen ist nur für Matches des aktuellen Spieltags möglich, nicht für bereits
            // geplante zukünftige (deren Aufstellungen/Marktwerte noch nicht final sind) — die
            // Tipp-Karte bleibt für die anderen im Frontend komplett unsichtbar statt nur die
            // Buttons zu deaktivieren.
            $result['is_current_matchday'] = $matchday ? $this->isCurrentH2HMatchday($matchday, $seasonId) : false;
        }

        if ($locked || $preview) {
            $allQ = $this->con_league->prepare(
                "SELECT hp.manager_id, m.manager_name, m.alias, hp.pick
                 FROM h2h_prediction hp JOIN manager m ON m.id = hp.manager_id
                 WHERE hp.match_id = :mid ORDER BY m.manager_name ASC"
            );
            $allQ->execute([':mid' => $matchId]);
            $entries = $allQ->fetchAll(PDO::FETCH_ASSOC);

            if ($locked) {
                $result['entries'] = $entries;
            } else {
                $result['preview']         = true;
                $result['preview_entries'] = $entries;
            }
        }

        return $result;
    }

    /**
     * True, wenn $matchday (mit mind. number + division_id) der Spieltag mit der kleinsten
     * number ist, der in derselben Saison+Division noch nicht abgeschlossen ist — also der
     * aktuell laufende bzw. als nächstes anstehende. Fehlt division_id (sollte praktisch nie
     * vorkommen, da jeder h2h_match einer echten matchday-Zeile zugeordnet ist), wird defensiv
     * nicht blockiert (true), statt Tippen ohne erkennbaren Grund unmöglich zu machen.
     */
    private function isCurrentH2HMatchday(array $matchday, string $seasonId): bool
    {
        if (empty($matchday['division_id'])) return true;

        $curQ = $this->con->prepare(
            "SELECT MIN(number) FROM matchday WHERE season_id = :sid AND division_id = :did AND completed = 0"
        );
        $curQ->execute([':sid' => $seasonId, ':did' => $matchday['division_id']]);
        $currentNumber = $curQ->fetchColumn();

        return $currentNumber !== false && (int) $matchday['number'] === (int) $currentNumber;
    }

    /**
     * Setzt/ändert den Tipp eines Managers für ein Match (Upsert per ON DUPLICATE KEY UPDATE über
     * UNIQUE(match_id, manager_id) — Tipp bleibt bis Anpfiff beliebig oft änderbar). Serverseitige
     * Absicherung des Frontend-Gates aus getH2HPredictionState() (is_current_matchday) — nur
     * Matches des aktuellen Spieltags sind tippbar, nicht bereits geplante zukünftige.
     */
    public function submitH2HPrediction(string $matchId, string $managerId, string $pick): array
    {
        $mq = $this->con_league->prepare(
            "SELECT matchday_id FROM h2h_match WHERE id = :id LIMIT 1"
        );
        $mq->execute([':id' => $matchId]);
        $matchdayId = $mq->fetchColumn();
        if (!$matchdayId) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Match nicht gefunden'];
        }

        $mdq = $this->con->prepare(
            "SELECT season_id, division_id, number,
                    (kickoff_date IS NOT NULL AND kickoff_date <= NOW()) AS locked
             FROM matchday WHERE id = :id LIMIT 1"
        );
        $mdq->execute([':id' => $matchdayId]);
        $matchday = $mdq->fetch(PDO::FETCH_ASSOC);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Spieltag nicht gefunden'];
        }
        if ((bool) $matchday['locked']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippphase beendet — Anpfiff war bereits'];
        }
        if (!$this->isCurrentH2HMatchday($matchday, $matchday['season_id'])) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippen ist nur für Matches des aktuellen Spieltags möglich'];
        }

        $this->con_league->prepare(
            "INSERT INTO h2h_prediction (match_id, manager_id, pick) VALUES (:mid, :man, :pick)
             ON DUPLICATE KEY UPDATE pick = VALUES(pick)"
        )->execute([':mid' => $matchId, ':man' => $managerId, ':pick' => $pick]);

        return ['status' => true];
    }
}
