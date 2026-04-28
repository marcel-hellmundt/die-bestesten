<?php

trait AchievementConditionsTrait
{
    // Alle Methoden: check_X(array $managerIds): array
    // Gibt die Teilmenge der manager_ids zurück, die die Bedingung erfüllen.
    // Cross-DB-Joins werden PHP-seitig aufgelöst (con = global, con_league = liga).

    public function check_season_champion(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Nur Saisons berücksichtigen, in denen ALLE Spieltage abgeschlossen sind
        $completedSeasons = $this->con->query(
            "SELECT season_id FROM matchday
             GROUP BY season_id
             HAVING COUNT(*) = SUM(completed) AND COUNT(*) > 0"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($completedSeasons))
            return [];

        $sPlh = implode(',', array_fill(0, count($completedSeasons), '?'));
        $stmt = $this->con->prepare(
            "SELECT id, season_id FROM matchday WHERE season_id IN ($sPlh)"
        );
        $stmt->execute($completedSeasons);
        $matchdays = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchdayToSeason = [];
        foreach ($matchdays as $row) {
            $matchdayToSeason[$row['id']] = $row['season_id'];
        }

        $mdIds = array_keys($matchdayToSeason);
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT t.manager_id, tr.matchday_id, tr.points
             FROM team t
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE tr.matchday_id IN ($mPlh)"
        );
        $stmt->execute($mdIds);
        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seasonTotals = [];
        foreach ($ratings as $row) {
            $sid = $matchdayToSeason[$row['matchday_id']] ?? null;
            if (!$sid)
                continue;
            $key = $row['manager_id'] . '|' . $sid;
            $seasonTotals[$key] = ($seasonTotals[$key] ?? 0) + (int) $row['points'];
        }

        $seasonMax = [];
        foreach ($seasonTotals as $key => $total) {
            [, $sid] = explode('|', $key, 2);
            if (!isset($seasonMax[$sid]) || $total > $seasonMax[$sid]) {
                $seasonMax[$sid] = $total;
            }
        }

        $winners = [];
        foreach ($seasonTotals as $key => $total) {
            [$mid, $sid] = explode('|', $key, 2);
            if ($total === ($seasonMax[$sid] ?? -1)) {
                $winners[] = $mid;
            }
        }

        return array_values(array_intersect($managerIds, array_unique($winners)));
    }

    public function check_ten_matchday_wins(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Nur Saisons ab 2017/2018 (start_date >= 2017-07-01)
        $matchdays = $this->con->query(
            "SELECT md.id, md.season_id FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE md.completed = 1 AND s.start_date >= '2017-07-01'"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchdays))
            return [];

        $matchdayToSeason = [];
        foreach ($matchdays as $row) {
            $matchdayToSeason[$row['id']] = $row['season_id'];
        }

        $mdIds = array_keys($matchdayToSeason);
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT tr.matchday_id, t.manager_id, tr.points
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

        $winCounts = [];
        foreach ($matchdayRatings as $mid => $rows) {
            $max = $matchdayMax[$mid];
            $sid = $matchdayToSeason[$mid];
            foreach ($rows as $row) {
                if ($row['points'] == $max) {
                    $key = $row['manager_id'] . '|' . $sid;
                    $winCounts[$key] = ($winCounts[$key] ?? 0) + 1;
                }
            }
        }

        $winners = [];
        foreach ($winCounts as $key => $count) {
            if ($count >= 10) {
                $winners[] = explode('|', $key, 2)[0];
            }
        }

        return array_values(array_intersect($managerIds, array_unique($winners)));
    }

    public function check_century(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Nur Saisons ab 2017/2018 (start_date >= 2017-07-01)
        $validMatchdayIds = $this->con->query(
            "SELECT md.id FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE s.start_date >= '2017-07-01'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($validMatchdayIds))
            return [];

        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $mPlh = implode(',', array_fill(0, count($validMatchdayIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.id IN ($plh) AND tr.matchday_id IN ($mPlh) AND tr.points >= 100"
        );
        $stmt->execute([...$managerIds, ...$validMatchdayIds]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function check_win_streak_3(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Nur Saisons ab 2017/2018 (start_date >= 2017-07-01)
        $matchdays = $this->con->query(
            "SELECT md.id, md.season_id, md.number
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
            "SELECT tr.matchday_id, t.manager_id, tr.points
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

        $matchdayWinners = [];
        foreach ($matchdayRatings as $mid => $rows) {
            $max = $matchdayMax[$mid];
            foreach ($rows as $row) {
                if ($row['points'] == $max) {
                    $matchdayWinners[$mid][] = $row['manager_id'];
                }
            }
        }

        $maxStreaks = [];
        $currentStreaks = [];
        $prevSeason = null;

        foreach ($matchdays as $md) {
            $mid = $md['id'];
            $sid = $md['season_id'];

            if ($sid !== $prevSeason) {
                $currentStreaks = [];
                $prevSeason = $sid;
            }

            $winners = $matchdayWinners[$mid] ?? [];
            $allMgrs = array_unique(array_merge(array_keys($currentStreaks), $winners));

            foreach ($allMgrs as $mgr) {
                if (in_array($mgr, $winners)) {
                    $currentStreaks[$mgr] = ($currentStreaks[$mgr] ?? 0) + 1;
                    $maxStreaks[$mgr] = max($maxStreaks[$mgr] ?? 0, $currentStreaks[$mgr]);
                } else {
                    $currentStreaks[$mgr] = 0;
                }
            }
        }

        $achievers = [];
        foreach ($maxStreaks as $mgr => $max) {
            if ($max >= 3) {
                $achievers[] = $mgr;
            }
        }

        return array_values(array_intersect($managerIds, array_unique($achievers)));
    }

    public function check_sds_4(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        $sdsRows = $this->con->query(
            "SELECT player_id, matchday_id FROM player_rating WHERE sds = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sdsRows))
            return [];

        $sdsKeySet = [];
        foreach ($sdsRows as $row) {
            $sdsKeySet[$row['player_id'] . '|' . $row['matchday_id']] = true;
        }

        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT m.id AS manager_id, tl.player_id, tl.matchday_id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_lineup tl ON tl.team_id = t.id AND tl.nominated = 1
             WHERE m.id IN ($plh)"
        );
        $stmt->execute($managerIds);
        $nominations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($nominations as $nom) {
            $key = $nom['player_id'] . '|' . $nom['matchday_id'];
            if (isset($sdsKeySet[$key])) {
                $mk = $nom['manager_id'] . '|' . $nom['matchday_id'];
                $counts[$mk] = ($counts[$mk] ?? 0) + 1;
            }
        }

        $achievers = [];
        foreach ($counts as $mk => $cnt) {
            if ($cnt >= 4) {
                $achievers[] = explode('|', $mk, 2)[0];
            }
        }

        return array_values(array_intersect($managerIds, array_unique($achievers)));
    }

    public function check_season_points_1400(array $managerIds): array
    {
        return $this->checkSeasonAggregate($managerIds, 'points', 1400);
    }

    public function check_season_goals_70(array $managerIds): array
    {
        return $this->checkSeasonAggregate($managerIds, 'goals', 70);
    }

    public function check_season_assists_60(array $managerIds): array
    {
        return $this->checkSeasonAggregate($managerIds, 'assists', 60);
    }

    private function checkSeasonAggregate(array $managerIds, string $column, int $threshold): array
    {
        $matchdays = $this->con->query(
            "SELECT id, season_id FROM matchday"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($matchdays))
            return [];

        $matchdayToSeason = [];
        foreach ($matchdays as $row) {
            $matchdayToSeason[$row['id']] = $row['season_id'];
        }

        $mdIds = array_keys($matchdayToSeason);
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $mPlh = implode(',', array_fill(0, count($mdIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT t.manager_id, tr.matchday_id, tr.`$column` AS val
             FROM team t
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE t.manager_id IN ($plh) AND tr.matchday_id IN ($mPlh)"
        );
        $stmt->execute([...$managerIds, ...$mdIds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seasonTotals = [];
        foreach ($rows as $row) {
            $sid = $matchdayToSeason[$row['matchday_id']] ?? null;
            if (!$sid)
                continue;
            $key = $row['manager_id'] . '|' . $sid;
            $seasonTotals[$key] = ($seasonTotals[$key] ?? 0) + (int) $row['val'];
        }

        $achievers = [];
        foreach ($seasonTotals as $key => $total) {
            if ($total >= $threshold) {
                $achievers[] = explode('|', $key, 2)[0];
            }
        }

        return array_values(array_intersect($managerIds, array_unique($achievers)));
    }

    public function check_datenkrake(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Alle player_rating IDs für completed Spieltage aus globaler DB
        $rows = $this->con->query(
            "SELECT pr.id AS rating_id, pr.matchday_id
             FROM player_rating pr
             JOIN matchday md ON md.id = pr.matchday_id AND md.completed = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows))
            return [];

        $ratingToMatchday = [];
        foreach ($rows as $row) {
            $ratingToMatchday[$row['rating_id']] = $row['matchday_id'];
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

        // Pro Spieltag: welche Manager haben beigetragen?
        $matchdayContributors = [];
        foreach ($contributions as $contrib) {
            $matchdayId = $ratingToMatchday[$contrib['player_rating_id']];
            $matchdayContributors[$matchdayId][$contrib['manager_id']] = true;
        }

        // Spieltage, bei denen alle Beiträge von genau einem Manager kommen
        $achievers = [];
        foreach ($matchdayContributors as $contributors) {
            if (count($contributors) === 1) {
                $achievers[] = array_key_first($contributors);
            }
        }

        return array_values(array_intersect($managerIds, array_unique($achievers)));
    }

    public function check_kleine_grosse(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Spieler mit genau 500.000 € Marktwert pro Saison aus globaler DB
        $cheapPlayers = $this->con->query(
            "SELECT player_id, season_id FROM player_in_season WHERE price = 500000"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cheapPlayers))
            return [];

        $cheapSet = [];
        foreach ($cheapPlayers as $row) {
            $cheapSet[$row['player_id']] = $row['season_id'];
        }

        $playerIds = array_keys($cheapSet);
        $pPlh = implode(',', array_fill(0, count($playerIds), '?'));
        $manPlh = implode(',', array_fill(0, count($managerIds), '?'));

        $stmt = $this->con_league->prepare(
            "SELECT pit.player_id, t.manager_id, t.season_id AS team_season_id
             FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.player_id IN ($pPlh) AND t.manager_id IN ($manPlh)"
        );
        $stmt->execute([...$playerIds, ...$managerIds]);
        $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($purchases))
            return [];

        // Nur Käufe in der gleichen Saison, in der der Spieler diesen Preis hat
        $candidates = [];
        foreach ($purchases as $row) {
            $globalSeason = $cheapSet[$row['player_id']] ?? null;
            if ($globalSeason && $globalSeason === $row['team_season_id']) {
                $key = $row['player_id'] . '|' . $globalSeason;
                $candidates[$key][] = $row['manager_id'];
            }
        }

        if (empty($candidates))
            return [];

        // Alle Spieltage der relevanten Saisons aus globaler DB
        $seasons = array_unique(array_values($cheapSet));
        $sPlh = implode(',', array_fill(0, count($seasons), '?'));
        $stmt = $this->con->prepare(
            "SELECT id, season_id FROM matchday WHERE season_id IN ($sPlh)"
        );
        $stmt->execute($seasons);
        $matchdayToSeason = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $md) {
            $matchdayToSeason[$md['id']] = $md['season_id'];
        }

        // Kumulierte Punkte der Kandidaten-Spieler pro Saison aus globaler DB
        $candPlayerIds = array_unique(array_map(fn($k) => explode('|', $k, 2)[0], array_keys($candidates)));
        $cpPlh = implode(',', array_fill(0, count($candPlayerIds), '?'));
        $stmt = $this->con->prepare(
            "SELECT player_id, matchday_id, points FROM player_rating
             WHERE player_id IN ($cpPlh) AND points IS NOT NULL"
        );
        $stmt->execute($candPlayerIds);

        $playerSeasonPoints = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rating) {
            $sid = $matchdayToSeason[$rating['matchday_id']] ?? null;
            if (!$sid)
                continue;
            $key = $rating['player_id'] . '|' . $sid;
            $playerSeasonPoints[$key] = ($playerSeasonPoints[$key] ?? 0) + (int) $rating['points'];
        }

        $achievers = [];
        foreach ($candidates as $key => $mgrIds) {
            if (($playerSeasonPoints[$key] ?? 0) >= 20) {
                foreach ($mgrIds as $mid) {
                    $achievers[] = $mid;
                }
            }
        }

        return array_values(array_intersect($managerIds, array_unique($achievers)));
    }

    public function check_der_pate(array $managerIds): array
    {
        if (empty($managerIds))
            return [];

        // Gewonnenes Gebot, bei dem mindestens 4 weitere Manager (success + lost) mitgeboten haben
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN offer o ON o.team_id = t.id AND o.status = 'success'
             WHERE m.id IN ($plh)
               AND (
                   SELECT COUNT(*) FROM offer o2
                   WHERE o2.transferwindow_id = o.transferwindow_id
                     AND o2.player_id = o.player_id
                     AND o2.status IN ('success', 'lost')
               ) >= 5"
        );
        $stmt->execute($managerIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
