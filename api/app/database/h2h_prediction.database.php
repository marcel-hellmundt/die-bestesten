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
    public function getH2HPredictionState(
        string $matchId, string $managerId, ?array $matchday, string $seasonId,
        ?string $homeManagerId, ?string $awayManagerId, bool $preview = false,
        bool $lineupsReady = true
    ): array {
        $kickoffDate = $matchday['kickoff_date'] ?? null;
        $locked      = $kickoffDate !== null && strtotime($kickoffDate) <= time();

        $result = ['locked' => $locked, 'kickoff_date' => $kickoffDate];

        if (!$locked) {
            $mine = $this->con_league->prepare(
                "SELECT pick, stake FROM h2h_prediction WHERE match_id = :mid AND manager_id = :man LIMIT 1"
            );
            $mine->execute([':mid' => $matchId, ':man' => $managerId]);
            $mineRow = $mine->fetch(PDO::FETCH_ASSOC);
            $result['my_pick']  = $mineRow['pick'] ?? null;
            // Eigener aktueller Einsatz in Lukaten (fiktive Wettwährung, siehe stake-Spalte) +
            // aktuelles Lukaten-Budget für diese Saison — beide nur relevant, solange die
            // Tippphase offen ist (analog my_pick).
            $result['my_stake'] = isset($mineRow['stake']) ? (int) $mineRow['stake'] : null;
            $result['budget']   = $this->getManagerLukatenBudget($managerId, $seasonId);
            // Tippen ist nur für Matches des aktuellen Spieltags möglich, nicht für bereits
            // geplante zukünftige (deren Aufstellungen/Marktwerte noch nicht final sind) — die
            // Tipp-Karte bleibt für die anderen im Frontend komplett unsichtbar statt nur die
            // Buttons zu deaktivieren.
            $result['is_current_matchday'] = $matchday ? $this->isCurrentH2HMatchday($matchday, $seasonId) : false;
            // Manager eines der beiden beteiligten Teams dürfen nicht auf ihr eigenes Match
            // tippen — sie könnten die angezeigte Quote durch Verändern der eigenen Aufstellung
            // bewusst manipulieren (siehe H2HTrait::calculateH2HOdds). Betrifft nur die eigene
            // Tipp-Abgabe, nicht die Sichtbarkeit der Tipps anderer Manager nach Anpfiff.
            $result['is_own_match'] = $managerId !== '' && ($managerId === $homeManagerId || $managerId === $awayManagerId);
            // Tippen erst, sobald beide Teams für diesen Spieltag eine Aufstellung (mind. 1
            // nominierter Spieler) gesetzt haben — vorher wäre die angezeigte Quote der
            // "keine Daten"-Fallback aus calculateH2HOdds() (z.B. 2,70/3,85/2,70 für beide
            // Teams gleich), was Manager fälschlich als echte Einschätzung lesen. Karte bleibt
            // dann komplett unsichtbar, analog is_current_matchday/is_own_match.
            $result['lineups_ready'] = $lineupsReady;
            // Reine Anzahl (nicht wer/was) ist vor Anpfiff unbedenklich mitzugeben — die
            // einzelnen Tipps selbst bleiben über $entries/preview_entries weiterhin geheim.
            $countQ = $this->con_league->prepare(
                "SELECT COUNT(*) FROM h2h_prediction WHERE match_id = :mid"
            );
            $countQ->execute([':mid' => $matchId]);
            $result['submitted_count'] = (int) $countQ->fetchColumn();
        }

        if ($locked || $preview) {
            $allQ = $this->con_league->prepare(
                "SELECT hp.manager_id, m.manager_name, m.alias, hp.pick, hp.odds
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
     * Aktuelles Lukaten-Budget (fiktive Wettwährung) eines Managers für eine Saison — kein
     * gespeicherter Kontostand, sondern live aus h2h_prediction berechnet (analog zum
     * Echtgeld-Teambudget, das per SUM(amount) aus der transaction-Tabelle kommt): jeder Manager
     * startet mit 100, jeder gesetzte Einsatz wird sofort "ausgegeben" (auch bei offenen/
     * verlorenen Tipps), nur gewonnene Tipps zahlen stake*odds (den vollen Betrag inkl.
     * ursprünglichem Einsatz) zurück. Nur Zeilen mit stake IS NOT NULL zählen — alte Tipps ohne
     * Einsatz (vor Einführung dieses Features) bleiben unberücksichtigt.
     *
     * $excludeMatchId blendet den eigenen (alten) Einsatz auf genau dieses Match aus der Summe
     * aus — nötig, um beim Ändern eines bestehenden Einsatzes den vollen (unveränderten)
     * verfügbaren Rahmen zu prüfen, statt den Manager durch seinen eigenen alten Einsatz auf
     * dasselbe Match zu blockieren (siehe submitH2HPrediction()).
     */
    public function getManagerLukatenBudget(string $managerId, string $seasonId, ?string $excludeMatchId = null): float
    {
        $sql = "SELECT 100
                  - COALESCE(SUM(hp.stake), 0)
                  + COALESCE(SUM(CASE WHEN hp.result = 'won' THEN hp.stake * hp.odds ELSE 0 END), 0) AS budget
                FROM h2h_prediction hp
                JOIN h2h_match hm ON hm.id = hp.match_id
                WHERE hp.manager_id = :man AND hm.season_id = :season AND hp.stake IS NOT NULL";
        $params = [':man' => $managerId, ':season' => $seasonId];
        if ($excludeMatchId !== null) {
            $sql .= " AND hp.match_id != :exclude";
            $params[':exclude'] = $excludeMatchId;
        }
        $q = $this->con_league->prepare($sql);
        $q->execute($params);
        return (float) $q->fetchColumn();
    }

    /**
     * Lukaten-Budget des Managers für die aktive Saison — Kurzform für den GET
     * /h2h_prediction/budget-Endpunkt.
     */
    public function getManagerLukatenBudgetForActiveSeason(string $managerId): float
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) return 100.0;
        return $this->getManagerLukatenBudget($managerId, $seasonId);
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

    // True, wenn beide Teams für diesen Spieltag eine VOLLSTÄNDIGE gültige Aufstellung (genau 11
    // nominierte Spieler) haben — nicht nur irgendeine Teilaufstellung. Ein gespeichertes Lineup
    // ist laut PATCH-Sanity-Check immer entweder komplett (11) oder ein noch erreichbarer
    // Zwischenstand (<11, z.B. beim schrittweisen Aufstellen oder nach einer Lücke durch POST
    // /sell, siehe dort) — 11 ist also gleichbedeutend mit "eine der 7 gültigen Formationen ist
    // tatsächlich komplett gefüllt". Server-seitige Absicherung des Frontend-Gates (lineups_ready
    // in getH2HPredictionState()) — mit nur einer Teilaufstellung auf einer Seite wäre die Quote
    // verzerrt (fehlende Spieler zählen nicht mit) und ein Tipp darauf irreführend.
    private function bothTeamsHaveLineup(string $homeTeamId, string $awayTeamId, string $matchdayId): bool
    {
        $q = $this->con_league->prepare(
            "SELECT team_id, COUNT(*) AS cnt FROM team_lineup
             WHERE matchday_id = :mid AND team_id IN (:home, :away) AND nominated = 1
             GROUP BY team_id HAVING cnt = 11"
        );
        $q->execute([':mid' => $matchdayId, ':home' => $homeTeamId, ':away' => $awayTeamId]);
        return count($q->fetchAll(PDO::FETCH_COLUMN)) === 2;
    }

    /**
     * Aktuell bebettbare H2H-Matches der aktiven Saison — Spieltag ist der aktuelle (kleinste noch
     * nicht abgeschlossene number in Saison+Division), beide Teams haben bereits eine vollständige
     * Aufstellung (bothTeamsHaveLineup()), der Manager führt keines der beiden Teams selbst, UND
     * hat für dieses Match noch keinen Tipp abgegeben. Fürs Wettbüro (webapp:
     * BettingOfficeComponent) — Liste "noch offener" Tipp-Möglichkeiten mit derselben
     * Quoten+Buttons-UI wie die H2H-Match-Detailseite, daher wird deren getH2HMatchDetail() pro
     * Match wiederverwendet statt die Odds-Berechnung hier zu duplizieren.
     */
    public function getAvailableH2HMatches(string $managerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) return [];

        $divisionId = $this->getLeagueDivisionId();
        if (!$divisionId) return [];

        $mdq = $this->con->prepare(
            "SELECT id, number FROM matchday
             WHERE season_id = :sid AND division_id = :did AND completed = 0
             ORDER BY number ASC LIMIT 1"
        );
        $mdq->execute([':sid' => $seasonId, ':did' => $divisionId]);
        $matchday = $mdq->fetch(PDO::FETCH_ASSOC);
        if (!$matchday) return [];

        $q = $this->con_league->prepare(
            "SELECT hm.id, hm.home_team_id, hm.away_team_id,
                    th.manager_id AS home_manager_id, ta.manager_id AS away_manager_id
             FROM h2h_match hm
             JOIN team th ON th.id = hm.home_team_id
             JOIN team ta ON ta.id = hm.away_team_id
             WHERE hm.matchday_id = :mid"
        );
        $q->execute([':mid' => $matchday['id']]);
        $matches = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($matches)) return [];

        $matchIds = array_column($matches, 'id');
        $ph       = implode(',', array_fill(0, count($matchIds), '?'));
        $predQ    = $this->con_league->prepare(
            "SELECT match_id FROM h2h_prediction WHERE manager_id = ? AND match_id IN ($ph)"
        );
        $predQ->execute(array_merge([$managerId], $matchIds));
        $alreadyPredicted = array_flip($predQ->fetchAll(PDO::FETCH_COLUMN));

        $available = [];
        foreach ($matches as $m) {
            if ($m['home_manager_id'] === $managerId || $m['away_manager_id'] === $managerId) continue;
            if (isset($alreadyPredicted[$m['id']])) continue;
            if (!$this->bothTeamsHaveLineup($m['home_team_id'], $m['away_team_id'], $matchday['id'])) continue;

            $detail = $this->getH2HMatchDetail($m['id']);
            if (!$detail) continue;

            $available[] = [
                'match_id'        => $m['id'],
                'matchday_number' => $matchday['number'],
                'season_id'       => $detail['match']['season_id'],
                'home_team'       => $detail['home_team'],
                'away_team'       => $detail['away_team'],
                'odds'            => $detail['odds'],
            ];
        }
        return $available;
    }

    /**
     * Setzt/ändert den Tipp eines Managers für ein Match (Upsert per ON DUPLICATE KEY UPDATE über
     * UNIQUE(match_id, manager_id) — Tipp bleibt bis Anpfiff beliebig oft änderbar). Serverseitige
     * Absicherung des Frontend-Gates aus getH2HPredictionState() (is_current_matchday) — nur
     * Matches des aktuellen Spieltags sind tippbar, nicht bereits geplante zukünftige.
     *
     * $odds: die im Frontend zum Zeitpunkt der Tippabgabe für genau diesen Pick angezeigte
     * Pseudo-Quote (siehe H2HTrait::calculateH2HOdds), vom Client mitgeschickt und unverändert
     * gespeichert — kein Server-seitiger Neuberechnungs-Aufwand nötig, da rein informativ ohne
     * echte Einsätze. Die Quote kann sich bis Anpfiff durch Aufstellungsänderungen noch ändern;
     * dieser Snapshot hält fest, was der Manager beim Tippen tatsächlich gesehen hat.
     */
    public function submitH2HPrediction(string $matchId, string $managerId, string $pick, ?float $odds, ?int $stake = null): array
    {
        $mq = $this->con_league->prepare(
            "SELECT matchday_id, home_team_id, away_team_id FROM h2h_match WHERE id = :id LIMIT 1"
        );
        $mq->execute([':id' => $matchId]);
        $match = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Match nicht gefunden'];
        }
        $matchdayId = $match['matchday_id'];

        // Manager eines der beiden beteiligten Teams dürfen nicht auf ihr eigenes Match tippen —
        // sie könnten die angezeigte Quote durch Verändern der eigenen Aufstellung manipulieren.
        $tq = $this->con_league->prepare(
            "SELECT manager_id FROM team WHERE id IN (:home, :away)"
        );
        $tq->execute([':home' => $match['home_team_id'], ':away' => $match['away_team_id']]);
        if (in_array($managerId, $tq->fetchAll(PDO::FETCH_COLUMN), true)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippen auf ein eigenes Match ist nicht möglich'];
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
        if (!$this->bothTeamsHaveLineup($match['home_team_id'], $match['away_team_id'], $matchdayId)) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippen ist erst möglich, sobald beide Teams eine Aufstellung abgegeben haben'];
        }

        // Einsatz in Lukaten (fiktive Wettwährung) ist optional — null lässt einen Tipp wie
        // bisher ohne Budget-Auswirkung zu. Wenn gesetzt: ganzzahlig, mindestens 1, höchstens das
        // aktuell verfügbare Budget (der eigene alte Einsatz auf GENAU dieses Match zählt dabei
        // nicht mit, siehe getManagerLukatenBudget()'s $excludeMatchId — sonst könnte ein
        // bestehender Einsatz nie erhöht werden).
        if ($stake !== null) {
            if ($stake < 1) {
                http_response_code(422);
                return ['status' => false, 'message' => 'Einsatz muss mindestens 1 Lukat betragen'];
            }
            $budget = $this->getManagerLukatenBudget($managerId, $matchday['season_id'], $matchId);
            if ($stake > $budget) {
                http_response_code(422);
                return ['status' => false, 'message' => 'Einsatz übersteigt dein aktuelles Budget'];
            }
        }

        $this->con_league->prepare(
            "INSERT INTO h2h_prediction (match_id, manager_id, pick, odds, stake) VALUES (:mid, :man, :pick, :odds, :stake)
             ON DUPLICATE KEY UPDATE pick = VALUES(pick), odds = VALUES(odds), stake = VALUES(stake)"
        )->execute([':mid' => $matchId, ':man' => $managerId, ':pick' => $pick, ':odds' => $odds, ':stake' => $stake]);

        return ['status' => true, 'budget' => $this->getManagerLukatenBudget($managerId, $matchday['season_id'])];
    }

    /**
     * Eigenen Tipp wieder entfernen (Klick auf den bereits ausgewählten Pick) — nur bis Anpfiff,
     * danach wie bei submitH2HPrediction() gesperrt. Kein 404 wenn ohnehin kein Tipp vorhanden
     * war (idempotent), das Frontend ruft dies ohnehin nur bei bestehendem eigenen Pick auf.
     */
    public function deleteH2HPrediction(string $matchId, string $managerId): array
    {
        $mq = $this->con_league->prepare(
            "SELECT matchday_id FROM h2h_match WHERE id = :id LIMIT 1"
        );
        $mq->execute([':id' => $matchId]);
        $match = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$match) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Match nicht gefunden'];
        }

        $mdq = $this->con->prepare(
            "SELECT season_id, (kickoff_date IS NOT NULL AND kickoff_date <= NOW()) AS locked
             FROM matchday WHERE id = :id LIMIT 1"
        );
        $mdq->execute([':id' => $match['matchday_id']]);
        $matchday = $mdq->fetch(PDO::FETCH_ASSOC);
        if ($matchday && (bool) $matchday['locked']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Tippphase beendet — Anpfiff war bereits'];
        }

        $this->con_league->prepare(
            "DELETE FROM h2h_prediction WHERE match_id = :mid AND manager_id = :man"
        )->execute([':mid' => $matchId, ':man' => $managerId]);

        // Ein evtl. gesetzter Einsatz wird durchs Löschen automatisch wieder freigegeben (reine
        // Formel-Konsequenz, siehe getManagerLukatenBudget()) — nur die Response muss das frische
        // Budget noch mitliefern.
        $budget = $matchday ? $this->getManagerLukatenBudget($managerId, $matchday['season_id']) : null;
        return ['status' => true, 'budget' => $budget];
    }

    /**
     * Nach Spieltagsabschluss aufgerufen (siehe MatchdayController::patch(), direkt nach
     * finalizeMatchday() — braucht die dort frisch geschriebenen team_rating-Zeilen). Berechnet
     * für jedes H2H-Match dieses Spieltags das tatsächliche Ergebnis (home/draw/away) über
     * H2HTrait::h2hGoals() und setzt bei allen zugehörigen Tipps result auf 'won' (pick ==
     * Ergebnis) oder 'lost'. AND result = 'open' macht den Aufruf idempotent, falls ein Spieltag
     * je erneut abgeschlossen werden sollte.
     */
    public function evaluateH2HPredictionResults(string $matchdayId): int
    {
        $mq = $this->con_league->prepare(
            "SELECT id, home_team_id, away_team_id FROM h2h_match WHERE matchday_id = :mid"
        );
        $mq->execute([':mid' => $matchdayId]);
        $matches = $mq->fetchAll(PDO::FETCH_ASSOC);
        if (empty($matches)) return 0;

        $teamIds = array_values(array_unique(array_merge(
            array_column($matches, 'home_team_id'), array_column($matches, 'away_team_id')
        )));
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $rq = $this->con_league->prepare(
            "SELECT team_id, goals, sds_defender, assists, invalid
             FROM team_rating WHERE matchday_id = ? AND team_id IN ($ph)"
        );
        $rq->execute(array_merge([$matchdayId], $teamIds));
        $ratingMap = array_column($rq->fetchAll(PDO::FETCH_ASSOC), null, 'team_id');

        $markWon  = $this->con_league->prepare(
            "UPDATE h2h_prediction SET result = 'won'  WHERE match_id = :mid AND pick = :pick AND result = 'open'"
        );
        $markLost = $this->con_league->prepare(
            "UPDATE h2h_prediction SET result = 'lost' WHERE match_id = :mid AND pick <> :pick AND result = 'open'"
        );

        $updated = 0;
        foreach ($matches as $m) {
            $goals = $this->h2hGoals($ratingMap[$m['home_team_id']] ?? null, $ratingMap[$m['away_team_id']] ?? null);
            if ($goals['home'] === null || $goals['away'] === null) continue;

            $outcome = $goals['home'] === $goals['away']
                ? 'draw'
                : ($goals['home'] > $goals['away'] ? 'home' : 'away');

            $markWon->execute([':mid' => $m['id'], ':pick' => $outcome]);
            $updated += $markWon->rowCount();
            $markLost->execute([':mid' => $m['id'], ':pick' => $outcome]);
            $updated += $markLost->rowCount();
        }

        return $updated;
    }

    /**
     * Alle Tipps eines Managers über alle Saisons/Matches hinweg, fürs Wettbüro
     * (webapp: BettingOfficeComponent) — je Tipp die Partie, das Endergebnis (h2hGoals(), null
     * solange noch keine team_rating-Zeilen vorliegen), der eigene Pick, die zum Tippzeitpunkt
     * angezeigte Quote sowie result (open/won/lost). Absteigend nach Anpfiff sortiert (neueste
     * zuerst, älteste unten) — matchday liegt in der globalen DB (con), daher zweite Abfrage statt
     * JOIN über die Liga-DB-Verbindung.
     */
    public function getMyH2HPredictions(string $managerId): array
    {
        $q = $this->con_league->prepare(
            "SELECT hp.match_id, hp.pick, hp.odds, hp.stake, hp.result,
                    hm.matchday_id, hm.home_team_id, hm.away_team_id,
                    th.team_name AS home_team_name, th.color_primary AS home_color,
                    ta.team_name AS away_team_name, ta.color_primary AS away_color
             FROM h2h_prediction hp
             JOIN h2h_match hm ON hm.id = hp.match_id
             JOIN team th ON th.id = hm.home_team_id
             JOIN team ta ON ta.id = hm.away_team_id
             WHERE hp.manager_id = :man"
        );
        $q->execute([':man' => $managerId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        // Produktions-DB liefert pick/result (ENUM ... CHARACTER SET utf8mb4) mitunter als
        // ucs2/utf16 zurück, was Null-Bytes einstreut (\0h\0o\0m\0e) — dieselbe Ursache wie bei
        // h2h_match.phase in H2HTrait::getH2HOverview(). Ungefiltert lässt das json_encode() für
        // die gesamte Response scheitern (liefert dann `false` statt eines Arrays), wodurch das
        // Frontend trotz vorhandener Tipps eine leere Liste zeigt.
        foreach ($rows as &$row) {
            $row['pick']   = str_replace("\0", '', $row['pick']);
            $row['result'] = str_replace("\0", '', $row['result']);
        }
        unset($row);

        $matchdayIds = array_values(array_unique(array_column($rows, 'matchday_id')));
        $mph = implode(',', array_fill(0, count($matchdayIds), '?'));
        $mdq = $this->con->prepare(
            "SELECT id, number, season_id, kickoff_date FROM matchday WHERE id IN ($mph)"
        );
        $mdq->execute($matchdayIds);
        $matchdayMap = array_column($mdq->fetchAll(PDO::FETCH_ASSOC), null, 'id');

        $teamIds = array_values(array_unique(array_merge(
            array_column($rows, 'home_team_id'), array_column($rows, 'away_team_id')
        )));
        $tph = implode(',', array_fill(0, count($teamIds), '?'));
        $rq = $this->con_league->prepare(
            "SELECT team_id, matchday_id, goals, assists, sds_defender, invalid
             FROM team_rating WHERE matchday_id IN ($mph) AND team_id IN ($tph)"
        );
        $rq->execute(array_merge($matchdayIds, $teamIds));
        $ratingMap = [];
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingMap[$r['matchday_id']][$r['team_id']] = $r;
        }

        usort($rows, fn($a, $b) => strcmp(
            $matchdayMap[$b['matchday_id']]['kickoff_date'] ?? '',
            $matchdayMap[$a['matchday_id']]['kickoff_date'] ?? ''
        ));

        return array_map(function ($row) use ($matchdayMap, $ratingMap) {
            $md    = $matchdayMap[$row['matchday_id']] ?? null;
            $goals = $this->h2hGoals(
                $ratingMap[$row['matchday_id']][$row['home_team_id']] ?? null,
                $ratingMap[$row['matchday_id']][$row['away_team_id']] ?? null
            );

            return [
                'match_id'         => $row['match_id'],
                'matchday_number'  => $md['number']    ?? null,
                'season_id'        => $md['season_id'] ?? null,
                'home_team_id'     => $row['home_team_id'],
                'home_team_name'   => $row['home_team_name'],
                'home_color'       => $row['home_color'],
                'away_team_id'     => $row['away_team_id'],
                'away_team_name'   => $row['away_team_name'],
                'away_color'       => $row['away_color'],
                'home_goals'       => $goals['home'],
                'away_goals'       => $goals['away'],
                'pick'             => $row['pick'],
                // DECIMAL-Spalte kommt von PDO als String zurück — Cast hält den Frontend-Typ
                // (number|null) ehrlich.
                'odds'             => $row['odds'] !== null ? (float) $row['odds'] : null,
                'stake'            => $row['stake'] !== null ? (int) $row['stake'] : null,
                // Voller Rückzahlungsbetrag in Lukaten bei gewonnenem, gestaktem Tipp (inkl.
                // ursprünglichem Einsatz) — null bei fehlendem Einsatz oder result != 'won'.
                'payout'           => ($row['stake'] !== null && $row['result'] === 'won')
                    ? round((float) $row['stake'] * (float) $row['odds'], 2)
                    : null,
                'result'           => $row['result'],
            ];
        }, $rows);
    }

    /**
     * Alle Manager, die bereits mindestens einen ausgewerteten H2H-Tipp haben (result won/lost —
     * noch offene Tipps zählen nicht, da deren Ausgang für andere Manager noch geheim ist), mit
     * ihrer Anzahl korrekter Tipps (result='won') — fürs Wettbüro (webapp: BettingOfficeComponent),
     * dort je Sieg ein reward.png-Icon. Absteigend nach Siegen sortiert.
     */
    public function getH2HPredictionStandings(): array
    {
        $rows = $this->con_league->query(
            "SELECT hp.manager_id, m.manager_name, m.alias, hp.match_id, hp.pick, hp.odds, hp.result,
                    hm.matchday_id, hm.home_team_id, hm.away_team_id,
                    th.team_name AS home_team_name, th.color_primary AS home_color,
                    ta.team_name AS away_team_name, ta.color_primary AS away_color
             FROM h2h_prediction hp
             JOIN manager m ON m.id = hp.manager_id
             JOIN h2h_match hm ON hm.id = hp.match_id
             JOIN team th ON th.id = hm.home_team_id
             JOIN team ta ON ta.id = hm.away_team_id
             WHERE hp.result <> 'open'
             ORDER BY m.manager_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [];

        // Für Vereinslogos (Saison des Matches) und das Endergebnis (h2hGoals()) im Reward-Icon-
        // Tooltip — gleiches Muster wie getMyH2HPredictions().
        $matchdayIds = array_values(array_unique(array_column($rows, 'matchday_id')));
        $mph = implode(',', array_fill(0, count($matchdayIds), '?'));
        $mdq = $this->con->prepare("SELECT id, season_id FROM matchday WHERE id IN ($mph)");
        $mdq->execute($matchdayIds);
        $seasonByMatchday = array_column($mdq->fetchAll(PDO::FETCH_ASSOC), 'season_id', 'id');

        $teamIds = array_values(array_unique(array_merge(
            array_column($rows, 'home_team_id'), array_column($rows, 'away_team_id')
        )));
        $tph = implode(',', array_fill(0, count($teamIds), '?'));
        $rq = $this->con_league->prepare(
            "SELECT team_id, matchday_id, goals, assists, sds_defender, invalid
             FROM team_rating WHERE matchday_id IN ($mph) AND team_id IN ($tph)"
        );
        $rq->execute(array_merge($matchdayIds, $teamIds));
        $ratingMap = [];
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingMap[$r['matchday_id']][$r['team_id']] = $r;
        }

        // Pro Manager gruppieren statt per SQL GROUP BY zu aggregieren — won_matches braucht das
        // Detail jeder einzelnen Sieg-Zeile fürs Frontend-Tooltip (Match, Logos, Endergebnis,
        // individuell eingelockte Quote), nicht nur die Zählsumme. wins bleibt trotzdem auch für
        // Manager mit ausschließlich verlorenen Tipps bei 0 statt ganz aus der Liste zu fallen
        // (gleiches Einschlusskriterium wie vorher: mind. ein AUSGEWERTETER Tipp, result <> 'open').
        $byManager = [];
        foreach ($rows as $row) {
            $mid = $row['manager_id'];
            if (!isset($byManager[$mid])) {
                $byManager[$mid] = [
                    'manager_id'   => $mid,
                    'manager_name' => $row['manager_name'],
                    'alias'        => $row['alias'],
                    'wins'         => 0,
                    'won_matches'  => [],
                ];
            }
            if ($row['result'] === 'won') {
                $byManager[$mid]['wins']++;
                $goals = $this->h2hGoals(
                    $ratingMap[$row['matchday_id']][$row['home_team_id']] ?? null,
                    $ratingMap[$row['matchday_id']][$row['away_team_id']] ?? null
                );
                $byManager[$mid]['won_matches'][] = [
                    'match_id'       => $row['match_id'],
                    'season_id'      => $seasonByMatchday[$row['matchday_id']] ?? null,
                    'home_team_id'   => $row['home_team_id'],
                    'home_team_name' => $row['home_team_name'],
                    'home_color'     => $row['home_color'],
                    'away_team_id'   => $row['away_team_id'],
                    'away_team_name' => $row['away_team_name'],
                    'away_color'     => $row['away_color'],
                    'home_goals'     => $goals['home'],
                    'away_goals'     => $goals['away'],
                    'pick'           => $row['pick'],
                    // DECIMAL-Spalte kommt von PDO als String zurück — ohne Cast bricht z.B. ein
                    // .toFixed() im Frontend auf dem vermeintlichen number-Wert.
                    'odds'           => $row['odds'] !== null ? (float) $row['odds'] : null,
                ];
            }
        }

        $result = array_values($byManager);
        usort($result, fn($a, $b) => $b['wins'] <=> $a['wins'] ?: strcmp($a['manager_name'], $b['manager_name']));

        return $result;
    }

    /**
     * Alle Manager mit mindestens einem gestakten Tipp (stake IS NOT NULL) in der aktiven
     * Saison, mit ihrem aktuellen Lukaten-Budget (siehe getManagerLukatenBudget()) — fürs
     * Wettbüro (Bestico), zweite Bestenliste neben den Sieg-Zählern. Absteigend nach Budget
     * sortiert.
     */
    public function getLukatenStandings(): array
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) return [];

        $managerIdsQ = $this->con_league->prepare(
            "SELECT DISTINCT hp.manager_id
             FROM h2h_prediction hp
             JOIN h2h_match hm ON hm.id = hp.match_id
             WHERE hp.stake IS NOT NULL AND hm.season_id = :season"
        );
        $managerIdsQ->execute([':season' => $seasonId]);
        $ids = $managerIdsQ->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) return [];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $mq = $this->con_league->prepare(
            "SELECT id AS manager_id, manager_name, alias FROM manager WHERE id IN ($ph)"
        );
        $mq->execute($ids);
        $managers = $mq->fetchAll(PDO::FETCH_ASSOC);

        foreach ($managers as &$m) {
            $m['budget'] = $this->getManagerLukatenBudget($m['manager_id'], $seasonId);
        }
        unset($m);

        usort($managers, fn($a, $b) => $b['budget'] <=> $a['budget'] ?: strcmp($a['manager_name'], $b['manager_name']));

        return $managers;
    }
}
