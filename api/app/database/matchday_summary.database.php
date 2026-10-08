<?php

/**
 * Spieltags-Zusammenfassung — nach dem Abschluss eines Spieltags (PATCH /matchday/:id completed=true) bekommt jeder
 * Manager eine Zusammenfassung, die die Webapp einmal groß einblendet (wie neue Packs oder Achievements): Punkte,
 * Platz am Spieltag und in der Tabelle, wer getroffen hat, Einnahmen fürs Budget, Strafe, H2H-Ergebnis — und darunter,
 * was nicht zum Spiel gehört: Lukaten für Einträge, Tipps, neue Packs, Achievements.
 *
 * Beim Abschluss wird je Manager und Liga eine Zeile in matchday_summary (globale DB) festgehalten — der Inhalt als
 * JSON, so wie er in dem Moment galt. Die Webapp holt die noch nicht gesehenen der letzten show_days Tage ab
 * (GET /matchday_summary) und markiert sie beim Einblenden als gesehen; über die Benachrichtigung zum Spieltag lässt
 * sich die Zusammenfassung später wieder öffnen (GET /matchday_summary/:id). Für die Vorschau in der Verwaltung
 * rechnet previewMatchdaySummary() dasselbe live aus, ohne etwas zu schreiben.
 *
 * Wie der Abschluss selbst läuft alles im Kontext der Liga, in der abgeschlossen wurde (con_league).
 */
trait MatchdaySummaryTrait
{
    protected function matchdaySummaryConfig(): array
    {
        return [
            'show_days' => 5, // so lange nach dem Abschluss wird eine noch nicht gesehene Zusammenfassung eingeblendet
        ];
    }

    private ?bool $matchdaySummaryReadyCache = null;

    /** Tabelle vorhanden? (Migration) — je Request einmal geprüft. */
    private function matchdaySummaryReady(): bool
    {
        if ($this->matchdaySummaryReadyCache !== null) return $this->matchdaySummaryReadyCache;
        try {
            $this->con->query("SELECT 1 FROM matchday_summary LIMIT 1");
            return $this->matchdaySummaryReadyCache = true;
        } catch (\Throwable $e) {
            return $this->matchdaySummaryReadyCache = false;
        }
    }

    /** Liga des Requests (aus dem JWT, sonst die per DB_NAME_LEAGUE konfigurierte Deployment-Liga). */
    private function matchdaySummaryLeague(): array
    {
        $leagueId = $GLOBALS['auth_league_id'] ?? null;
        if ($leagueId) {
            $q = $this->con->prepare("SELECT id, name FROM league WHERE id = ? LIMIT 1");
            $q->execute([$leagueId]);
        } else {
            $q = $this->con->prepare("SELECT id, name FROM league WHERE db_name = ? LIMIT 1");
            $q->execute([$_ENV['DB_NAME_LEAGUE'] ?? '']);
        }
        return $q->fetch(PDO::FETCH_ASSOC) ?: ['id' => null, 'name' => null];
    }

    /**
     * Zusammenfassungen eines Spieltags in der aktuellen Liga berechnen: [manager_id => Inhalt]. Liest nur.
     * Einen Eintrag bekommt jeder aktive Manager mit einem gewerteten oder ungültigen Team an diesem Spieltag — und
     * wer hier kein Team hat, aber Lukaten für Einträge bekommen oder getippt hat (dann ohne Spiel-Teil).
     * $newAchievements = 'new' aus evaluateAchievements(): was beim Abschluss neu vergeben wurde (später nicht mehr
     * herleitbar, earned_at ist das Datum der Leistung).
     */
    private function buildMatchdaySummaries(string $matchdayId, ?string $onlyManagerId = null, array $newAchievements = []): array
    {
        $mq = $this->con->prepare("SELECT id, number, season_id, division_id FROM matchday WHERE id = ?");
        $mq->execute([$matchdayId]);
        $md = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$md) return [];
        $number = (int) $md['number'];
        $league = $this->matchdaySummaryLeague();

        // Teams der Saison und ihre Wertung an diesem Spieltag
        $tq = $this->con_league->prepare(
            "SELECT id, manager_id, team_name, color_primary AS color, season_id FROM team WHERE season_id = ?"
        );
        $tq->execute([$md['season_id']]);
        $teams = [];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $t['color'] = $this->resolveColor($t['color']);
            $teams[$t['id']] = $t;
        }

        $rq = $this->con_league->prepare(
            "SELECT tr.team_id, tr.points, tr.max_points, tr.goals, tr.assists, tr.red_cards, tr.yellow_red_cards,
                    tr.clean_sheet, tr.sds, tr.sds_defender, tr.invalid
             FROM team_rating tr WHERE tr.matchday_id = ?"
        );
        $rq->execute([$matchdayId]);
        $ratings = array_values(array_filter($rq->fetchAll(PDO::FETCH_ASSOC), fn($r) => isset($teams[$r['team_id']])));
        $ratingByTeam = array_column($ratings, null, 'team_id');

        // Platz am Spieltag: Standard-Wettkampf-Rang (1224) unter den gewerteten Teams
        $validPoints = array_map(fn($r) => (int) $r['points'], array_filter($ratings, fn($r) => !(int) $r['invalid']));
        $rankIn = fn(array $all, int $points): int => 1 + count(array_filter($all, fn($p) => $p > $points));
        $fineByTeam = array_column($this->assignFines($ratings, 'points'), 'fine', 'team_id');

        // Tabelle nach diesem Spieltag und davor: Saisonpunkte bis einschließlich Spieltag N bzw. N−1
        $nq = $this->con->prepare("SELECT id, number FROM matchday WHERE season_id = ? AND division_id = ?");
        $nq->execute([$md['season_id'], $md['division_id']]);
        $numberById = $nq->fetchAll(PDO::FETCH_KEY_PAIR);
        $after = $before = array_fill_keys(array_keys($teams), 0);
        $hasBefore = false;
        $sq = $this->con_league->prepare(
            "SELECT tr.team_id, tr.matchday_id, tr.points FROM team_rating tr JOIN team t ON t.id = tr.team_id WHERE t.season_id = ?"
        );
        $sq->execute([$md['season_id']]);
        foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = $numberById[$r['matchday_id']] ?? null;
            if ($n === null || (int) $n > $number) continue;
            $after[$r['team_id']] += (int) $r['points'];
            if ((int) $n < $number) {
                $before[$r['team_id']] += (int) $r['points'];
                $hasBefore = true;
            }
        }

        // Wer getroffen, vorbereitet oder Spieler des Spiels geworden ist — unter den aufgestellten Spielern
        $lq = $this->con_league->prepare("SELECT team_id, player_id FROM team_lineup WHERE matchday_id = ? AND nominated = 1");
        $lq->execute([$matchdayId]);
        $lineup = [];
        foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $r) $lineup[$r['team_id']][] = $r['player_id'];
        $playerIds = array_values(array_unique(array_merge([], ...array_values($lineup))));
        $scorers = [];
        if ($playerIds) {
            $ph = implode(',', array_fill(0, count($playerIds), '?'));
            $pq = $this->con->prepare(
                "SELECT pr.player_id, p.displayname, COALESCE(pr.points, 0) AS points, COALESCE(pr.goals, 0) AS goals,
                        COALESCE(pr.assists, 0) AS assists, COALESCE(pr.sds, 0) AS sds,
                        pis.position, COALESCE(pis.photo_uploaded, 0) AS photo_uploaded
                 FROM player_rating pr
                 JOIN player p ON p.id = pr.player_id
                 LEFT JOIN player_in_season pis
                        ON pis.player_id = pr.player_id AND pis.season_id = ? AND pis.division_id = ?
                 WHERE pr.matchday_id = ? AND pr.player_id IN ($ph)
                   AND (pr.goals > 0 OR pr.assists > 0 OR pr.sds = 1)"
            );
            $pq->execute(array_merge([$md['season_id'], $md['division_id'], $matchdayId], $playerIds));
            $scorers = array_column($pq->fetchAll(PDO::FETCH_ASSOC), null, 'player_id');
        }

        // Einnahmen fürs Budget (Buchung "Spieltagseinnahmen" aus finalizeMatchday())
        $iq = $this->con_league->prepare(
            "SELECT team_id, SUM(amount) FROM transaction WHERE matchday_id = ? AND reason = 'Spieltagseinnahmen' GROUP BY team_id"
        );
        $iq->execute([$matchdayId]);
        $incomeByTeam = $iq->fetchAll(PDO::FETCH_KEY_PAIR);

        // H2H-Match des Teams an diesem Spieltag und die Tipps der Manager auf die Matches des Spieltags
        $h2hByTeam = [];
        $bets = [];
        try {
            $hq = $this->con_league->prepare("SELECT id, phase, home_team_id, away_team_id FROM h2h_match WHERE matchday_id = ?");
            $hq->execute([$matchdayId]);
            foreach ($hq->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $goals = $this->h2hGoals($ratingByTeam[$m['home_team_id']] ?? null, $ratingByTeam[$m['away_team_id']] ?? null);
                if ($goals['home'] === null || $goals['away'] === null) continue;
                foreach ([['home', 'away'], ['away', 'home']] as [$own, $other]) {
                    $opponent = $teams[$m[$other . '_team_id']] ?? null;
                    if ($opponent === null) continue;
                    $for = $goals[$own];
                    $against = $goals[$other];
                    $h2hByTeam[$m[$own . '_team_id']] = [
                        'match_id'      => $m['id'],
                        'phase'         => str_replace("\0", '', (string) $m['phase']), // siehe H2HTrait::getH2HOverview()
                        'home'          => $own === 'home',
                        'opponent'      => [
                            'team_id' => $opponent['id'], 'team_name' => $opponent['team_name'],
                            'color' => $opponent['color'], 'season_id' => $opponent['season_id'],
                        ],
                        'goals_for'     => $for,
                        'goals_against' => $against,
                        'outcome'       => $for === $against ? 'draw' : ($for > $against ? 'win' : 'loss'),
                    ];
                }
            }

            $bq = $this->con_league->prepare(
                "SELECT hp.manager_id, hp.odds, hp.stake, hp.result
                 FROM h2h_prediction hp JOIN h2h_match hm ON hm.id = hp.match_id
                 WHERE hm.matchday_id = ?"
            );
            $bq->execute([$matchdayId]);
            foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $bets[$b['manager_id']] ??= ['tips' => 0, 'correct' => 0, 'stakes' => 0, 'payouts' => 0.0];
                $won = $b['result'] === 'won';
                $bets[$b['manager_id']]['tips']++;
                if ($won) $bets[$b['manager_id']]['correct']++;
                if ($b['stake'] !== null) {
                    $bets[$b['manager_id']]['stakes'] += (int) $b['stake'];
                    if ($won) $bets[$b['manager_id']]['payouts'] += (int) $b['stake'] * (float) $b['odds'];
                }
            }
        } catch (\Throwable $e) {
            if (!$this->lukatenFeatureMissing($e)) throw $e; // Liga ohne H2H: kein Match, keine Tipps
        }

        // Lukaten für die Einträge dieses Spieltags (Buchung entries:{matchday_id}), mit Aufteilung nach Art
        $entries = [];
        if ($this->lukatenLedgerReady()) {
            $eq = $this->con->prepare("SELECT manager_id, amount FROM lukaten_transaction WHERE source = 'entries' AND source_key = ?");
            $eq->execute(["entries:$matchdayId"]);
            foreach ($eq->fetchAll(PDO::FETCH_KEY_PAIR) as $managerId => $amount) {
                $entries[$managerId] = ['amount' => (float) $amount, 'by_type' => ['participation' => 0, 'note' => 0, 'stats' => 0]];
            }
            if ($entries) {
                $cq = $this->con->prepare(
                    "SELECT mc.manager_id, mc.contribution_type, COUNT(*) AS cnt
                     FROM maintainer_contribution mc
                     JOIN player_rating pr ON pr.id = mc.player_rating_id
                     WHERE pr.matchday_id = ? AND {$this->lukatenFirstEntrySql()}
                     GROUP BY mc.manager_id, mc.contribution_type"
                );
                $cq->execute([$matchdayId]);
                foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (isset($entries[$r['manager_id']]['by_type'][$r['contribution_type']])) {
                        $entries[$r['manager_id']]['by_type'][$r['contribution_type']] = (int) $r['cnt'];
                    }
                }
            }
        }

        // Packs aus diesem Spieltag: Spieltagsbester und Punkte-Meilensteine (Schlüssel wie grantStickerMatchdayPacks())
        $packs = [];
        try {
            $interval = (int) $this->stickerConfig()['milestone_interval'];
            $candidates = [];
            $owners = [];
            foreach ($ratings as $r) {
                $teamId = $r['team_id'];
                $owners[$teams[$teamId]['manager_id']] = true;
                $candidates["matchday_best:$teamId:$matchdayId"] = ['source' => 'matchday_best', 'milestone_points' => null];
                if ($interval <= 0) continue;
                $reached = $after[$teamId] ?? 0;
                for ($m = intdiv(max($reached - (int) $r['points'], 0), $interval) + 1; $m * $interval <= $reached; $m++) {
                    $candidates["milestone:$teamId:" . ($m * $interval)] = ['source' => 'milestone', 'milestone_points' => $m * $interval];
                }
            }
            if ($candidates) {
                $mph = implode(',', array_fill(0, count($owners), '?'));
                $kph = implode(',', array_fill(0, count($candidates), '?'));
                $kq = $this->con->prepare(
                    "SELECT manager_id, source_key, size FROM sticker_pack WHERE manager_id IN ($mph) AND source_key IN ($kph)"
                );
                $kq->execute(array_merge(array_keys($owners), array_keys($candidates)));
                foreach ($kq->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $packs[$p['manager_id']][] = $candidates[$p['source_key']] + ['size' => (int) $p['size']];
                }
            }
        } catch (\Throwable $e) {
            if (!$this->lukatenFeatureMissing($e)) throw $e; // ohne Sticker-Tabellen: keine Packs
        }

        $achievements = [];
        foreach ($newAchievements as $a) {
            if (empty($a['manager_id'])) continue;
            $achievements[$a['manager_id']][] = [
                'name' => $a['achievement_name'] ?? '', 'icon' => $a['icon'] ?? null,
                'level' => $a['level'] ?? 'gold', 'reason' => $a['reason'] ?? null,
            ];
        }

        // Wer bekommt eine Zusammenfassung: Manager mit Team an diesem Spieltag, dazu wer nur Extras hat
        $teamByManager = [];
        foreach ($ratings as $r) $teamByManager[$teams[$r['team_id']]['manager_id']] = $r['team_id'];
        $managerIds = array_values(array_unique(array_merge(array_keys($teamByManager), array_keys($entries), array_keys($bets))));
        if ($onlyManagerId !== null) $managerIds = array_values(array_intersect($managerIds, [$onlyManagerId]));
        if (!$managerIds) return [];
        $ph = implode(',', array_fill(0, count($managerIds), '?'));
        $aq = $this->con->prepare("SELECT id FROM manager WHERE status = 'active' AND id IN ($ph)");
        $aq->execute($managerIds);
        $active = array_flip($aq->fetchAll(PDO::FETCH_COLUMN));

        $summaries = [];
        foreach ($managerIds as $managerId) {
            if (!isset($active[$managerId])) continue;
            $teamId = $teamByManager[$managerId] ?? null;
            $team = null;
            $result = null;
            if ($teamId !== null) {
                $t = $teams[$teamId];
                $r = $ratingByTeam[$teamId];
                $invalid = (bool) (int) $r['invalid'];
                $points  = (int) $r['points'];
                $team = ['id' => $t['id'], 'team_name' => $t['team_name'], 'color' => $t['color'], 'season_id' => $t['season_id']];

                $highlights = [];
                if (!$invalid) {
                    foreach ($lineup[$teamId] ?? [] as $playerId) {
                        $p = $scorers[$playerId] ?? null;
                        if ($p === null) continue;
                        $highlights[] = [
                            'player_id'       => $p['player_id'],
                            'displayname'     => $p['displayname'],
                            'position'        => $p['position'],
                            'photo_season_id' => (int) $p['photo_uploaded'] ? $md['season_id'] : null,
                            'goals'           => (int) $p['goals'],
                            'assists'         => (int) $p['assists'],
                            'sds'             => (bool) (int) $p['sds'],
                            'points'          => (int) $p['points'],
                        ];
                    }
                    usort($highlights, fn($a, $b) => [$b['sds'], $b['goals'], $b['assists'], $b['points']] <=> [$a['sds'], $a['goals'], $a['assists'], $a['points']]);
                }

                $result = [
                    'valid'             => !$invalid,
                    'points'            => $points,
                    'max_points'        => (int) $r['max_points'],
                    'rank'              => $invalid ? null : $rankIn($validPoints, $points),
                    'teams'             => count($validPoints),
                    'table_rank'        => $rankIn($after, $after[$teamId]),
                    'table_rank_before' => $hasBefore ? $rankIn($before, $before[$teamId]) : null,
                    'table_points'      => $after[$teamId],
                    'table_teams'       => count($teams),
                    'stats'             => [
                        'goals' => (int) $r['goals'], 'assists' => (int) $r['assists'], 'clean_sheets' => (int) $r['clean_sheet'],
                        'sds' => (int) $r['sds'], 'red_cards' => (int) $r['red_cards'], 'yellow_red_cards' => (int) $r['yellow_red_cards'],
                    ],
                    'highlights'        => $highlights,
                    'income'            => (float) ($incomeByTeam[$teamId] ?? 0),
                    'fine'              => (float) ($fineByTeam[$teamId] ?? 0),
                    'h2h'               => $h2hByTeam[$teamId] ?? null,
                ];
            }

            $bet = $bets[$managerId] ?? null;
            if ($bet !== null) $bet['payouts'] = round($bet['payouts'], 2);
            $extras = [
                'lukaten_entries' => $entries[$managerId] ?? null,
                'bets'            => $bet,
                'packs'           => $packs[$managerId] ?? [],
                'achievements'    => $achievements[$managerId] ?? [],
            ];
            if ($result === null && $extras['lukaten_entries'] === null && $extras['bets'] === null) continue;

            $summaries[$managerId] = [
                'version'  => 1,
                'matchday' => ['id' => $md['id'], 'number' => $number, 'season_id' => $md['season_id']],
                'league'   => $league,
                'team'     => $team,
                'result'   => $result,
                'extras'   => $extras,
            ];
        }
        return $summaries;
    }

    /**
     * Beim Spieltagsabschluss: je Manager die Zusammenfassung festhalten. $notificationIds = [manager_id =>
     * notification_id] der Benachrichtigung "Spieltag N abgeschlossen" — darüber lässt sie sich später wieder öffnen.
     * Erneutes Abschließen aktualisiert den Inhalt, lässt "gesehen" aber stehen (niemand bekommt sie zweimal).
     * Rückgabe: Anzahl geschriebener Zusammenfassungen.
     */
    public function createMatchdaySummaries(string $matchdayId, array $newAchievements = [], array $notificationIds = []): int
    {
        if (!$this->matchdaySummaryReady()) return 0;
        $summaries = $this->buildMatchdaySummaries($matchdayId, null, $newAchievements);
        $leagueId = $summaries ? (reset($summaries)['league']['id'] ?? null) : null;
        if (!$leagueId) return 0;

        // Was es schon gibt: aus einer anderen Liga (derselbe Spieltag dort schon abgeschlossen) → Lukaten für
        // Einträge und Achievements stehen bereits dort, nicht doppelt zeigen. Aus dieser Liga (erneuter Abschluss) →
        // die damals neuen Achievements behalten, beim zweiten Lauf sind sie nicht mehr "neu".
        $xq = $this->con->prepare("SELECT manager_id, league_id, payload FROM matchday_summary WHERE matchday_id = ?");
        $xq->execute([$matchdayId]);
        $shownElsewhere = [];
        $earlier = [];
        foreach ($xq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $old = json_decode((string) $row['payload'], true) ?: [];
            if ($row['league_id'] === $leagueId) {
                $earlier[$row['manager_id']] = $old['extras']['achievements'] ?? [];
            } else {
                $shownElsewhere[$row['manager_id']] = true;
            }
        }

        $ins = $this->con->prepare(
            "INSERT INTO matchday_summary (manager_id, league_id, matchday_id, notification_id, payload)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload),
                                     notification_id = COALESCE(VALUES(notification_id), notification_id)"
        );
        $count = 0;
        foreach ($summaries as $managerId => $summary) {
            if (isset($shownElsewhere[$managerId])) {
                $summary['extras']['lukaten_entries'] = null;
                $summary['extras']['achievements'] = [];
                if ($summary['result'] === null && $summary['extras']['bets'] === null) continue;
            } elseif (!$summary['extras']['achievements'] && !empty($earlier[$managerId])) {
                $summary['extras']['achievements'] = $earlier[$managerId];
            }
            $ins->execute([
                $managerId, $leagueId, $matchdayId, $notificationIds[$managerId] ?? null,
                json_encode($summary, JSON_UNESCAPED_UNICODE),
            ]);
            $count++;
        }
        return $count;
    }

    private function matchdaySummaryFromRow(array $row): array
    {
        return ['id' => $row['id'], 'created_at' => $row['created_at'], 'seen_at' => $row['seen_at']]
            + (json_decode((string) $row['payload'], true) ?: []);
    }

    /**
     * Was die Webapp einblendet: die noch nicht gesehenen Zusammenfassungen des Managers aus den letzten show_days
     * Tagen (alle seine Ligen), älteste zuerst. Ohne Tabelle (Migration fehlt) ready=false.
     */
    public function getPendingMatchdaySummaries(string $managerId): array
    {
        $days = (int) $this->matchdaySummaryConfig()['show_days'];
        if (!$this->matchdaySummaryReady()) return ['ready' => false, 'show_days' => $days, 'summaries' => []];
        $q = $this->con->prepare(
            "SELECT id, payload, created_at, seen_at FROM matchday_summary
             WHERE manager_id = ? AND seen_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
             ORDER BY created_at ASC, id ASC"
        );
        $q->execute([$managerId]);
        return [
            'ready'     => true,
            'show_days' => $days,
            'summaries' => array_map(fn($r) => $this->matchdaySummaryFromRow($r), $q->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** Eine eigene Zusammenfassung (zum Wieder-Öffnen aus der Benachrichtigung), egal wie alt — sonst null. */
    public function getMatchdaySummary(string $id, string $managerId): ?array
    {
        if (!$this->matchdaySummaryReady()) return null;
        $q = $this->con->prepare("SELECT id, payload, created_at, seen_at FROM matchday_summary WHERE id = ? AND manager_id = ?");
        $q->execute([$id, $managerId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->matchdaySummaryFromRow($row) : null;
    }

    /** Eigene Zusammenfassungen als gesehen markieren (beim Einblenden, geräteübergreifend). */
    public function markMatchdaySummariesSeen(string $managerId, array $ids): int
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        if (!$ids || !$this->matchdaySummaryReady()) return 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->con->prepare(
            "UPDATE matchday_summary SET seen_at = NOW() WHERE manager_id = ? AND seen_at IS NULL AND id IN ($ph)"
        );
        $q->execute(array_merge([$managerId], $ids));
        return $q->rowCount();
    }

    /** [notification_id => id] der Zusammenfassungen eines Managers — für den Button in der Benachrichtigung. */
    private function matchdaySummaryIdsByNotification(string $managerId): array
    {
        if (!$this->matchdaySummaryReady()) return [];
        $q = $this->con->prepare(
            "SELECT notification_id, id FROM matchday_summary WHERE manager_id = ? AND notification_id IS NOT NULL"
        );
        $q->execute([$managerId]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Vorschau für die Verwaltung: die Zusammenfassung eines Managers für einen Spieltag der aktuellen Liga, live
     * berechnet — schreibt nichts und zeigt niemandem etwas an. Achievements lassen sich nachträglich nicht
     * herleiten; gibt es zu dem Spieltag schon eine festgehaltene Zusammenfassung, kommen sie von dort.
     * null, wenn es für den Manager an dem Spieltag nichts zu zeigen gibt.
     */
    public function previewMatchdaySummary(string $managerId, string $matchdayId): ?array
    {
        $summary = $this->buildMatchdaySummaries($matchdayId, $managerId)[$managerId] ?? null;
        if ($summary === null) return null;
        if ($summary['league']['id'] && $this->matchdaySummaryReady()) {
            $q = $this->con->prepare(
                "SELECT payload FROM matchday_summary WHERE manager_id = ? AND league_id = ? AND matchday_id = ?"
            );
            $q->execute([$managerId, $summary['league']['id'], $matchdayId]);
            $stored = json_decode((string) ($q->fetchColumn() ?: ''), true) ?: [];
            $summary['extras']['achievements'] = $stored['extras']['achievements'] ?? [];
        }
        return ['id' => null, 'created_at' => null, 'seen_at' => null] + $summary;
    }
}
