<?php

trait H2HPredictionTrait
{
    /**
     * Tipp-Status für ein H2H-Match, eingebettet in H2HTrait::getH2HMatchDetail(). Vor Anpfiff der
     * Matchday (Analog zu PowerrankingTrait::getMatchday1LockStatus) nur der eigene Tipp — die
     * Tipps anderer Manager bleiben geheim, bis der Anpfiff sie sperrt; danach alle Tipps offen.
     * $matchday kommt bereits geladen vom Aufrufer (getH2HMatchDetail hat es ohnehin schon per
     * matchday_id nachgeschlagen), um hier keine zweite Abfrage für den Sperrstatus zu brauchen.
     */
    public function getH2HPredictionState(string $matchId, string $managerId, ?array $matchday): array
    {
        $kickoffDate = $matchday['kickoff_date'] ?? null;
        $locked      = $kickoffDate !== null && strtotime($kickoffDate) <= time();

        if (!$locked) {
            $mine = $this->con_league->prepare(
                "SELECT pick FROM h2h_prediction WHERE match_id = :mid AND manager_id = :man LIMIT 1"
            );
            $mine->execute([':mid' => $matchId, ':man' => $managerId]);

            return [
                'locked'       => false,
                'kickoff_date' => $kickoffDate,
                'my_pick'      => $mine->fetchColumn() ?: null,
            ];
        }

        $allQ = $this->con_league->prepare(
            "SELECT hp.manager_id, m.manager_name, m.alias, hp.pick
             FROM h2h_prediction hp JOIN manager m ON m.id = hp.manager_id
             WHERE hp.match_id = :mid ORDER BY m.manager_name ASC"
        );
        $allQ->execute([':mid' => $matchId]);

        return [
            'locked'       => true,
            'kickoff_date' => $kickoffDate,
            'entries'      => $allQ->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Setzt/ändert den Tipp eines Managers für ein Match (Upsert per ON DUPLICATE KEY UPDATE über
     * UNIQUE(match_id, manager_id) — Tipp bleibt bis Anpfiff beliebig oft änderbar).
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

        $kq = $this->con->prepare(
            "SELECT (kickoff_date IS NOT NULL AND kickoff_date <= NOW()) AS locked
             FROM matchday WHERE id = :id LIMIT 1"
        );
        $kq->execute([':id' => $matchdayId]);
        if ((bool) $kq->fetchColumn()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippphase beendet — Anpfiff war bereits'];
        }

        $this->con_league->prepare(
            "INSERT INTO h2h_prediction (match_id, manager_id, pick) VALUES (:mid, :man, :pick)
             ON DUPLICATE KEY UPDATE pick = VALUES(pick)"
        )->execute([':mid' => $matchId, ':man' => $managerId, ':pick' => $pick]);

        return ['status' => true];
    }
}
