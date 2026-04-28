<?php

trait AchievementConditionsTrait
{
    // Alle Methoden: check_X(array $managerIds): array<manager_id, ['reason'=>string,'earned_at'=>string]>
    // Cross-DB-Joins werden PHP-seitig aufgelöst (con = global, con_league = liga).

    private function seasonLabel(string $startDate): string
    {
        $year = (int) substr($startDate, 0, 4);
        return $year . '/' . substr((string) ($year + 1), 2, 2);
    }

    private function formatMio(int $value): string
    {
        $mio = $value / 1_000_000;
        $str = number_format($mio, 1, ',', '.');
        if (str_ends_with($str, ',0'))
            $str = substr($str, 0, -2);
        return $str . ' Mio';
    }

    public function check_season_champion(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $completedSeasons = $this->con->query(
            "SELECT season_id FROM matchday
             GROUP BY season_id
             HAVING COUNT(*) = SUM(completed) AND COUNT(*) > 0"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($completedSeasons))
            return [];

        $sPlh = implode(',', array_fill(0, count($completedSeasons), '?'));

        $stmt = $this->con->prepare(
            "SELECT id, season_id, kickoff_date FROM matchday WHERE season_id IN ($sPlh)"
        );
        $stmt->execute($completedSeasons);
        $matchdays = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchdayToSeason = [];
        $lastKickoff = [];
        foreach ($matchdays as $row) {
            $matchdayToSeason[$row['id']] = $row['season_id'];
            $sid = $row['season_id'];
            if (!isset($lastKickoff[$sid]) || $row['kickoff_date'] > $lastKickoff[$sid]) {
                $lastKickoff[$sid] = $row['kickoff_date'];
            }
        }

        $stmt = $this->con->prepare(
            "SELECT id, start_date FROM season WHERE id IN ($sPlh)"
        );
        $stmt->execute($completedSeasons);
        $seasonStartMap = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'start_date', 'id');

        $mdIds = array_keys($matchdayToSeason);
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT t.manager_id, t.team_name, tr.matchday_id, tr.points
             FROM team t
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE tr.matchday_id IN ($mPlh)"
        );
        $stmt->execute($mdIds);
        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seasonTotals = [];
        $teamNames = [];
        foreach ($ratings as $row) {
            $sid = $matchdayToSeason[$row['matchday_id']] ?? null;
            if (!$sid)
                continue;
            $key = $row['manager_id'] . '|' . $sid;
            $seasonTotals[$key] = ($seasonTotals[$key] ?? 0) + (int) $row['points'];
            $teamNames[$key] = $row['team_name'];
        }

        $seasonMax = [];
        foreach ($seasonTotals as $key => $total) {
            [, $sid] = explode('|', $key, 2);
            if (!isset($seasonMax[$sid]) || $total > $seasonMax[$sid]) {
                $seasonMax[$sid] = $total;
            }
        }

        // Pro Manager: früheste Saison, in der er Meister war
        $winnerEarliestSeason = [];
        foreach ($seasonTotals as $key => $total) {
            [$mid, $sid] = explode('|', $key, 2);
            if (!in_array($mid, $managerIds))
                continue;
            if ($total !== ($seasonMax[$sid] ?? -1))
                continue;
            $startDate = $seasonStartMap[$sid] ?? '9999-01-01';
            if (
                !isset($winnerEarliestSeason[$mid]) ||
                $startDate < ($seasonStartMap[$winnerEarliestSeason[$mid]] ?? '9999-01-01')
            ) {
                $winnerEarliestSeason[$mid] = $sid;
            }
        }

        $result = [];
        foreach ($winnerEarliestSeason as $mid => $sid) {
            $teamName = $teamNames[$mid . '|' . $sid] ?? '?';
            $label = $this->seasonLabel($seasonStartMap[$sid] ?? '');
            $result[$mid] = [
                'reason' => "$teamName ($label)",
                'earned_at' => $lastKickoff[$sid] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $result;
    }

    public function check_ten_matchday_wins(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $matchdays = $this->con->query(
            "SELECT md.id, md.season_id, md.number, md.kickoff_date, s.start_date AS season_start
             FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE md.completed = 1 AND s.start_date >= '2017-07-01'
             ORDER BY s.start_date ASC, md.number ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchdays))
            return [];

        $mdIds = array_column($matchdays, 'id');
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT tr.matchday_id, t.manager_id, t.team_name, t.season_id, tr.points
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             WHERE tr.matchday_id IN ($mPlh) AND tr.invalid = 0"
        );
        $stmt->execute($mdIds);
        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchdayMax = [];
        $matchdayRatings = [];
        foreach ($ratings as $row) {
            $mid = $row['matchday_id'];
            $matchdayRatings[$mid][] = $row;
            if (!isset($matchdayMax[$mid]) || $row['points'] > $matchdayMax[$mid]) {
                $matchdayMax[$mid] = $row['points'];
            }
        }

        // Siege chronologisch tracken; 10. Sieg pro Manager+Saison festhalten
        $winData = []; // manager_id|season_id => ['count','tenth_kickoff','team_name','season_start']
        foreach ($matchdays as $md) {
            $mid = $md['id'];
            $max = $matchdayMax[$mid] ?? null;
            if ($max === null)
                continue;
            foreach (($matchdayRatings[$mid] ?? []) as $row) {
                if ($row['points'] != $max)
                    continue;
                $mgr = $row['manager_id'];
                if (!in_array($mgr, $managerIds))
                    continue;
                $key = $mgr . '|' . $row['season_id'];
                if (!isset($winData[$key])) {
                    $winData[$key] = [
                        'count' => 0,
                        'tenth_kickoff' => null,
                        'team_name' => $row['team_name'],
                        'season_start' => $md['season_start'],
                    ];
                }
                $winData[$key]['count']++;
                if ($winData[$key]['count'] === 10) {
                    $winData[$key]['tenth_kickoff'] = $md['kickoff_date'];
                }
            }
        }

        // Pro Manager: früheste qualifizierende Saison
        $achievers = [];
        foreach ($winData as $key => $data) {
            if ($data['count'] < 10)
                continue;
            [$mgr] = explode('|', $key, 2);
            if (
                !isset($achievers[$mgr]) ||
                $data['season_start'] < $achievers[$mgr]['season_start']
            ) {
                $achievers[$mgr] = $data;
            }
        }

        $result = [];
        foreach ($achievers as $mgr => $data) {
            $label = $this->seasonLabel($data['season_start']);
            $result[$mgr] = [
                'reason' => "{$data['count']} Siege mit {$data['team_name']} ($label)",
                'earned_at' => $data['tenth_kickoff'] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $result;
    }

    public function check_century(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $validMatchdays = $this->con->query(
            "SELECT md.id, md.number, md.kickoff_date, md.season_id, s.start_date AS season_start
             FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE s.start_date >= '2017-07-01'"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($validMatchdays))
            return [];

        $mdMeta = array_column($validMatchdays, null, 'id');
        $mdIds = array_column($validMatchdays, 'id');
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT m.id AS manager_id, t.team_name, tr.matchday_id, tr.points
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.id IN ($plh) AND tr.matchday_id IN ($mPlh) AND tr.points >= 100
             ORDER BY tr.points DESC"
        );
        $stmt->execute([...$managerIds, ...$mdIds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pro Manager: Spieltag mit den meisten Punkten (>= 100)
        $achievers = [];
        foreach ($rows as $row) {
            $mgr = $row['manager_id'];
            $md = $mdMeta[$row['matchday_id']] ?? null;
            if (!$md)
                continue;
            if (!isset($achievers[$mgr]) || $row['points'] > $achievers[$mgr]['points']) {
                $achievers[$mgr] = [
                    'points' => $row['points'],
                    'team_name' => $row['team_name'],
                    'md_number' => $md['number'],
                    'kickoff_date' => $md['kickoff_date'],
                    'season_start' => $md['season_start'],
                ];
            }
        }

        $result = [];
        foreach ($achievers as $mgr => $data) {
            $label = $this->seasonLabel($data['season_start']);
            $result[$mgr] = [
                'reason' => "{$data['points']} Punkte mit {$data['team_name']}, Spieltag {$data['md_number']} ($label)",
                'earned_at' => $data['kickoff_date'],
            ];
        }
        return $result;
    }

    public function check_win_streak_3(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $matchdays = $this->con->query(
            "SELECT md.id, md.season_id, md.number, md.kickoff_date, s.start_date AS season_start
             FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE md.completed = 1 AND s.start_date >= '2017-07-01'
             ORDER BY md.season_id, md.number ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchdays))
            return [];

        $mdIds = array_column($matchdays, 'id');
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT tr.matchday_id, t.manager_id, t.team_name, tr.points
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             WHERE tr.matchday_id IN ($mPlh) AND tr.invalid = 0"
        );
        $stmt->execute($mdIds);
        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchdayMax = [];
        $matchdayRatings = [];
        foreach ($ratings as $row) {
            $mid = $row['matchday_id'];
            $matchdayRatings[$mid][] = $row;
            if (!isset($matchdayMax[$mid]) || $row['points'] > $matchdayMax[$mid]) {
                $matchdayMax[$mid] = $row['points'];
            }
        }

        // matchday_id => manager_id => team_name (nur Gewinner)
        $matchdayWinners = [];
        foreach ($matchdayRatings as $mid => $rows) {
            $max = $matchdayMax[$mid];
            foreach ($rows as $row) {
                if ($row['points'] == $max) {
                    $matchdayWinners[$mid][$row['manager_id']] = $row['team_name'];
                }
            }
        }

        $currentStreaks    = [];
        $streakStartNumber = [];
        $maxStreaks        = [];
        $firstStreakMeta   = [];
        $prevSeason        = null;

        foreach ($matchdays as $md) {
            $mid = $md['id'];
            $sid = $md['season_id'];

            if ($sid !== $prevSeason) {
                $currentStreaks    = [];
                $streakStartNumber = [];
                $prevSeason        = $sid;
            }

            $winners = array_keys($matchdayWinners[$mid] ?? []);
            $allMgrs = array_unique(array_merge(array_keys($currentStreaks), $winners));

            foreach ($allMgrs as $mgr) {
                if (in_array($mgr, $winners)) {
                    $prev = $currentStreaks[$mgr] ?? 0;
                    $currentStreaks[$mgr] = $prev + 1;
                    if ($prev === 0) {
                        $streakStartNumber[$mgr] = $md['number'];
                    }
                    $maxStreaks[$mgr] = max($maxStreaks[$mgr] ?? 0, $currentStreaks[$mgr]);
                    if ($currentStreaks[$mgr] === 3 && in_array($mgr, $managerIds) && !isset($firstStreakMeta[$mgr])) {
                        $firstStreakMeta[$mgr] = [
                            'team_name'    => $matchdayWinners[$mid][$mgr] ?? '?',
                            'kickoff_date' => $md['kickoff_date'],
                            'season_start' => $md['season_start'],
                            'start_number' => $streakStartNumber[$mgr] ?? $md['number'],
                            'end_number'   => $md['number'],
                        ];
                    }
                } else {
                    $currentStreaks[$mgr]    = 0;
                    $streakStartNumber[$mgr] = null;
                }
            }
        }

        $result = [];
        foreach ($maxStreaks as $mgr => $max) {
            if ($max < 3 || !in_array($mgr, $managerIds) || !isset($firstStreakMeta[$mgr]))
                continue;
            $meta  = $firstStreakMeta[$mgr];
            $label = $this->seasonLabel($meta['season_start']);
            $result[$mgr] = [
                'reason'    => 'Spieltage ' . $meta['start_number'] . '–' . $meta['end_number'] . " mit {$meta['team_name']} ($label)",
                'earned_at' => $meta['kickoff_date'],
            ];
        }
        return $result;
    }

    public function check_sds_4(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $sdsRows = $this->con->query(
            "SELECT pr.player_id, pr.matchday_id, md.number, md.kickoff_date, s.start_date AS season_start
             FROM player_rating pr
             JOIN matchday md ON md.id = pr.matchday_id
             JOIN season s ON s.id = md.season_id
             WHERE pr.sds = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sdsRows))
            return [];

        $sdsKeySet = [];
        $matchdayMeta = [];
        foreach ($sdsRows as $row) {
            $sdsKeySet[$row['player_id'] . '|' . $row['matchday_id']] = true;
            $matchdayMeta[$row['matchday_id']] = [
                'number' => $row['number'],
                'kickoff_date' => $row['kickoff_date'],
                'season_start' => $row['season_start'],
            ];
        }

        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT m.id AS manager_id, t.team_name, tl.player_id, tl.matchday_id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_lineup tl ON tl.team_id = t.id AND tl.nominated = 1
             WHERE m.id IN ($plh)"
        );
        $stmt->execute($managerIds);
        $nominations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        $matchdayTeamName = [];
        foreach ($nominations as $nom) {
            if (isset($sdsKeySet[$nom['player_id'] . '|' . $nom['matchday_id']])) {
                $mk = $nom['manager_id'] . '|' . $nom['matchday_id'];
                $counts[$mk] = ($counts[$mk] ?? 0) + 1;
                $matchdayTeamName[$mk] = $nom['team_name'];
            }
        }

        // Pro Manager: erster Spieltag mit >= 4 SDS (frühestes kickoff_date)
        $achievers = [];
        foreach ($counts as $mk => $cnt) {
            if ($cnt < 4)
                continue;
            [$mgr, $mid] = explode('|', $mk, 2);
            $meta = $matchdayMeta[$mid] ?? null;
            if (!$meta)
                continue;
            if (
                !isset($achievers[$mgr]) ||
                $meta['kickoff_date'] < $achievers[$mgr]['kickoff_date']
            ) {
                $achievers[$mgr] = [
                    'team_name' => $matchdayTeamName[$mk] ?? '?',
                    'md_number' => $meta['number'],
                    'kickoff_date' => $meta['kickoff_date'],
                    'season_start' => $meta['season_start'],
                    'count' => $cnt,
                ];
            }
        }

        $result = [];
        foreach ($achievers as $mgr => $data) {
            $label = $this->seasonLabel($data['season_start']);
            $result[$mgr] = [
                'reason' => "{$data['count']} SDS mit {$data['team_name']}, Spieltag {$data['md_number']} ($label)",
                'earned_at' => $data['kickoff_date'],
            ];
        }
        return $result;
    }

    public function check_season_points_1400(array $managerIds): array
    {
        return $this->checkSeasonAggregate($managerIds, 'points', 1400, 'Punkte');
    }

    public function check_season_goals_75(array $managerIds): array
    {
        return $this->checkSeasonAggregate($managerIds, 'goals', 75, 'Tore');
    }

    public function check_season_assists_60(array $managerIds): array
    {
        return $this->checkSeasonAggregate($managerIds, 'assists', 60, 'Vorlagen');
    }

    private function checkSeasonAggregate(array $managerIds, string $column, int $threshold, string $unit): array
    {
        $matchdays = $this->con->query(
            "SELECT md.id, md.season_id, md.kickoff_date, s.start_date AS season_start
             FROM matchday md JOIN season s ON s.id = md.season_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchdays))
            return [];

        $matchdayToSeason = [];
        $lastKickoff = [];
        $seasonStartMap = [];
        foreach ($matchdays as $row) {
            $matchdayToSeason[$row['id']] = $row['season_id'];
            $sid = $row['season_id'];
            $seasonStartMap[$sid] = $row['season_start'];
            if (!isset($lastKickoff[$sid]) || $row['kickoff_date'] > $lastKickoff[$sid]) {
                $lastKickoff[$sid] = $row['kickoff_date'];
            }
        }

        $mdIds = array_keys($matchdayToSeason);
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT t.manager_id, t.team_name, t.season_id AS team_season_id, tr.matchday_id, tr.`$column` AS val
             FROM team t
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE t.manager_id IN ($plh) AND tr.matchday_id IN ($mPlh)"
        );
        $stmt->execute([...$managerIds, ...$mdIds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seasonTotals = [];
        $teamNameMap = [];
        foreach ($rows as $row) {
            $sid = $row['team_season_id'];
            $key = $row['manager_id'] . '|' . $sid;
            $seasonTotals[$key] = ($seasonTotals[$key] ?? 0) + (int) $row['val'];
            $teamNameMap[$key] = $row['team_name'];
        }

        // Pro Manager: früheste qualifizierende Saison
        $achievers = [];
        foreach ($seasonTotals as $key => $total) {
            if ($total < $threshold)
                continue;
            [$mgr, $sid] = explode('|', $key, 2);
            if (!in_array($mgr, $managerIds))
                continue;
            $sStart = $seasonStartMap[$sid] ?? '9999-01-01';
            if (
                !isset($achievers[$mgr]) ||
                $sStart < ($seasonStartMap[$achievers[$mgr]['season_id']] ?? '9999-01-01')
            ) {
                $achievers[$mgr] = [
                    'season_id' => $sid,
                    'total' => $total,
                    'team_name' => $teamNameMap[$key] ?? '?',
                ];
            }
        }

        $result = [];
        foreach ($achievers as $mgr => $data) {
            $sid = $data['season_id'];
            $label = $this->seasonLabel($seasonStartMap[$sid] ?? '');
            $result[$mgr] = [
                'reason' => "{$data['total']} $unit mit {$data['team_name']} ($label)",
                'earned_at' => $lastKickoff[$sid] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $result;
    }

    public function check_datenkrake(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $rows = $this->con->query(
            "SELECT pr.id AS rating_id, md.id AS matchday_id, md.number, md.kickoff_date, s.start_date AS season_start
             FROM player_rating pr
             JOIN matchday md ON md.id = pr.matchday_id AND md.completed = 1
             JOIN season s ON s.id = md.season_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows))
            return [];

        $ratingToMatchday = [];
        $matchdayMeta = [];
        foreach ($rows as $row) {
            $ratingToMatchday[$row['rating_id']] = $row['matchday_id'];
            $matchdayMeta[$row['matchday_id']] = [
                'number' => $row['number'],
                'kickoff_date' => $row['kickoff_date'],
                'season_start' => $row['season_start'],
            ];
        }

        $ratingIds = array_keys($ratingToMatchday);
        $rPlh = implode(',', array_fill(0, count($ratingIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT player_rating_id, manager_id FROM maintainer_contribution
             WHERE player_rating_id IN ($rPlh)"
        );
        $stmt->execute($ratingIds);
        $contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($contributions))
            return [];

        $matchdayContributors = [];
        foreach ($contributions as $contrib) {
            $matchdayId = $ratingToMatchday[$contrib['player_rating_id']];
            $matchdayContributors[$matchdayId][$contrib['manager_id']] = true;
        }

        // Pro Manager: frühester Spieltag, an dem er Alleinbeitragender war
        $achievers = [];
        foreach ($matchdayContributors as $matchdayId => $contributors) {
            if (count($contributors) !== 1)
                continue;
            $mgr = array_key_first($contributors);
            if (!in_array($mgr, $managerIds))
                continue;
            $meta = $matchdayMeta[$matchdayId] ?? null;
            if (!$meta)
                continue;
            if (
                !isset($achievers[$mgr]) ||
                $meta['kickoff_date'] < $achievers[$mgr]['kickoff_date']
            ) {
                $achievers[$mgr] = $meta;
            }
        }

        $result = [];
        foreach ($achievers as $mgr => $meta) {
            $label = $this->seasonLabel($meta['season_start']);
            $result[$mgr] = [
                'reason' => "Spieltag {$meta['number']} ($label)",
                'earned_at' => $meta['kickoff_date'],
            ];
        }
        return $result;
    }

    public function check_kleine_grosse(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $cheapPlayers = $this->con->query(
            "SELECT player_id, season_id FROM player_in_season WHERE price = 500000"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cheapPlayers))
            return [];

        $cheapSet = []; // player_id => season_id
        foreach ($cheapPlayers as $row) {
            $cheapSet[$row['player_id']] = $row['season_id'];
        }

        $playerIds = array_keys($cheapSet);
        $pPlh  = implode(',', array_fill(0, count($playerIds),  '?'));
        $manPlh = implode(',', array_fill(0, count($managerIds), '?'));

        // Include from/to matchday to restrict point counting to ownership window
        $stmt = $this->con_league->prepare(
            "SELECT pit.player_id, pit.from_matchday_id, pit.to_matchday_id,
                    t.manager_id, t.season_id AS team_season_id
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.player_id IN ($pPlh) AND t.manager_id IN ($manPlh)"
        );
        $stmt->execute([...$playerIds, ...$managerIds]);
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($purchases))
            return [];

        // Keep only purchases where player's global season matches team's season
        $validPurchases = array_values(array_filter($purchases, function ($row) use ($cheapSet) {
            return ($cheapSet[$row['player_id']] ?? null) === $row['team_season_id'];
        }));

        if (empty($validPurchases))
            return [];

        $seasons = array_values(array_unique(array_column($validPurchases, 'team_season_id')));
        $sPlh = implode(',', array_fill(0, count($seasons), '?'));

        // Load matchday metadata (number needed to compare ownership window)
        $stmt = $this->con->prepare(
            "SELECT id, season_id, number, kickoff_date FROM matchday WHERE season_id IN ($sPlh)"
        );
        $stmt->execute($seasons);
        $mdMeta   = [];
        $lastKickoff = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $md) {
            $mdMeta[$md['id']] = $md;
            $sid = $md['season_id'];
            if (!isset($lastKickoff[$sid]) || $md['kickoff_date'] > $lastKickoff[$sid]) {
                $lastKickoff[$sid] = $md['kickoff_date'];
            }
        }

        $stmt = $this->con->prepare(
            "SELECT id, start_date FROM season WHERE id IN ($sPlh)"
        );
        $stmt->execute($seasons);
        $seasonStartMap = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'start_date', 'id');

        // Load all ratings for candidate players
        $candPlayerIds = array_values(array_unique(array_column($validPurchases, 'player_id')));
        $cpPlh = implode(',', array_fill(0, count($candPlayerIds), '?'));
        $stmt = $this->con->prepare(
            "SELECT player_id, matchday_id, points FROM player_rating
             WHERE player_id IN ($cpPlh) AND points IS NOT NULL"
        );
        $stmt->execute($candPlayerIds);

        $ratingsByPlayer = []; // player_id => matchday_id => points
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingsByPlayer[$r['player_id']][$r['matchday_id']] = (int) $r['points'];
        }

        // Per manager: sum points only for matchdays the player was in their team
        $bestPerManager = [];
        foreach ($validPurchases as $row) {
            $playerId  = $row['player_id'];
            $managerId = $row['manager_id'];
            $sid       = $row['team_season_id'];

            $fromNum = $mdMeta[$row['from_matchday_id']]['number'] ?? null;
            $toNum   = $row['to_matchday_id'] ? ($mdMeta[$row['to_matchday_id']]['number'] ?? PHP_INT_MAX) : PHP_INT_MAX;

            if ($fromNum === null)
                continue;

            $pts = 0;
            foreach ($ratingsByPlayer[$playerId] ?? [] as $mdId => $points) {
                $md = $mdMeta[$mdId] ?? null;
                if (!$md || $md['season_id'] !== $sid)
                    continue;
                if ($md['number'] >= $fromNum && $md['number'] < $toNum)
                    $pts += $points;
            }

            if ($pts > ($bestPerManager[$managerId]['pts'] ?? 0)) {
                $bestPerManager[$managerId] = ['pts' => $pts, 'player_id' => $playerId, 'season_id' => $sid];
            }
        }

        $qualifying = array_filter($bestPerManager, fn($d) => $d['pts'] >= 30);
        if (empty($qualifying))
            return [];

        $qPlayerIds = array_values(array_unique(array_column($qualifying, 'player_id')));
        $ppPlh = implode(',', array_fill(0, count($qPlayerIds), '?'));
        $stmt = $this->con->prepare(
            "SELECT id, displayname FROM player WHERE id IN ($ppPlh)"
        );
        $stmt->execute($qPlayerIds);
        $playerNames = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'displayname', 'id');

        $result = [];
        foreach ($qualifying as $mgr => $data) {
            $sid   = $data['season_id'];
            $label = $this->seasonLabel($seasonStartMap[$sid] ?? '');
            $name  = $playerNames[$data['player_id']] ?? '?';
            $result[$mgr] = [
                'reason'    => "$name, {$data['pts']} Pkt ($label)",
                'earned_at' => $lastKickoff[$sid] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $result;
    }

    public function check_zuschlag(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT m.id AS manager_id, o.player_id, o.offer_value, o.transferwindow_id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN offer o ON o.team_id = t.id AND o.status = 'success'
             WHERE m.id IN ($plh)
               AND (
                   SELECT COUNT(*) FROM offer o2
                   WHERE o2.transferwindow_id = o.transferwindow_id
                     AND o2.player_id = o.player_id
                     AND o2.status IN ('success', 'lost')
               ) >= 6
             ORDER BY o.created_at ASC"
        );
        $stmt->execute($managerIds);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($offers))
            return [];

        // Erstes qualifizierendes Gebot pro Manager
        $firstOffer = [];
        foreach ($offers as $row) {
            if (!isset($firstOffer[$row['manager_id']])) {
                $firstOffer[$row['manager_id']] = $row;
            }
        }

        // Transferfenster → Spieltag → kickoff_date + Saison
        $twIds = array_values(array_unique(array_column($firstOffer, 'transferwindow_id')));
        $twPlh = implode(',', array_fill(0, count($twIds), '?'));
        $stmt = $this->con->prepare(
            "SELECT tw.id AS tw_id, md.kickoff_date, s.start_date AS season_start
             FROM transferwindow tw
             JOIN matchday md ON md.id = tw.matchday_id
             JOIN season s ON s.id = md.season_id
             WHERE tw.id IN ($twPlh)"
        );
        $stmt->execute($twIds);
        $twMeta = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'tw_id');

        // Spieler-Displaynamen
        $playerIds = array_values(array_unique(array_column($firstOffer, 'player_id')));
        $pPlh = implode(',', array_fill(0, count($playerIds), '?'));
        $stmt = $this->con->prepare(
            "SELECT id, displayname FROM player WHERE id IN ($pPlh)"
        );
        $stmt->execute($playerIds);
        $playerNames = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'displayname', 'id');

        $result = [];
        foreach ($firstOffer as $mgr => $offer) {
            $meta = $twMeta[$offer['transferwindow_id']] ?? null;
            $name = $playerNames[$offer['player_id']] ?? '?';
            $mio = $this->formatMio($offer['offer_value']);
            $label = $meta ? $this->seasonLabel($meta['season_start']) : '?';
            $result[$mgr] = [
                'reason' => "$name, $mio ($label)",
                'earned_at' => $meta['kickoff_date'] ?? date('Y-m-d H:i:s'),
            ];
        }
        return $result;
    }

    public function check_kegelkasse(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // All completed matchdays sorted chronologically
        $matchdays = $this->con->query(
            "SELECT md.id, md.season_id, md.number, md.kickoff_date, s.start_date AS season_start
             FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE md.completed = 1 AND s.start_date >= '2017-01-01'
             ORDER BY s.start_date ASC, md.number ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchdays))
            return [];

        $mdIds = array_column($matchdays, 'id');
        $ph = implode(',', array_fill(0, count($mdIds), '?'));

        // All team_ratings for those matchdays (all teams, to determine last place per matchday)
        $stmt = $this->con_league->prepare(
            "SELECT t.manager_id, tr.matchday_id, COALESCE(tr.points, 0) AS points, t.team_name
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             WHERE tr.matchday_id IN ($ph)"
        );
        $stmt->execute(array_values($mdIds));
        $allRatings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Per-matchday: min points + per-manager entry
        $mdMinPoints = [];
        $mgrData = [];
        foreach ($allRatings as $r) {
            $mid = $r['matchday_id'];
            $pts = (int) $r['points'];
            if (!isset($mdMinPoints[$mid]) || $pts < $mdMinPoints[$mid]) {
                $mdMinPoints[$mid] = $pts;
            }
            $mgrData[$r['manager_id']][$mid] = ['points' => $pts, 'team_name' => $r['team_name']];
        }

        $result = [];
        $prevSeasonId = null;

        foreach ($managerIds as $managerId) {
            $streak = 0;
            $streakMds = [];
            $prevSeasonId = null;

            foreach ($matchdays as $md) {
                $mid = $md['id'];

                // Reset streak at season boundary
                if ($md['season_id'] !== $prevSeasonId) {
                    $streak = 0;
                    $streakMds = [];
                    $prevSeasonId = $md['season_id'];
                }

                $entry = $mgrData[$managerId][$mid] ?? null;
                $minPts = $mdMinPoints[$mid] ?? null;

                if ($entry === null || $minPts === null) {
                    $streak = 0;
                    $streakMds = [];
                    continue;
                }

                if ($entry['points'] <= $minPts) {
                    $streak++;
                    $streakMds[] = $md;

                    if ($streak === 3 && !isset($result[$managerId])) {
                        $label = $this->seasonLabel($md['season_start']);
                        $result[$managerId] = [
                            'reason' => 'Spieltage ' . $streakMds[0]['number'] . '–' . $md['number'] . ' mit ' . $entry['team_name'] . ' (' . $label . ')',
                            'earned_at' => $md['kickoff_date'],
                        ];
                    }
                } else {
                    $streak = 0;
                    $streakMds = [];
                }
            }
        }

        return $result;
    }
}
