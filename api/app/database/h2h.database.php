<?php

trait H2HTrait
{
    public function getH2HOverview(string $seasonId): array
    {
        // Load all groups for this season
        $gq = $this->con_league->prepare(
            "SELECT id, name, sort_index FROM h2h_group WHERE season_id = :s ORDER BY sort_index ASC, name ASC"
        );
        $gq->execute([':s' => $seasonId]);
        $groups = $gq->fetchAll(PDO::FETCH_ASSOC);

        // Load group-team assignments
        $groupIds = array_column($groups, 'id');
        $groupTeamMap = [];
        if (!empty($groupIds)) {
            $ph = implode(',', array_fill(0, count($groupIds), '?'));
            $gtq = $this->con_league->prepare("SELECT group_id, team_id FROM h2h_group_team WHERE group_id IN ($ph)");
            $gtq->execute($groupIds);
            foreach ($gtq->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $groupTeamMap[$row['group_id']][] = $row['team_id'];
            }
        }

        // Load all h2h_matches for this season
        $mq = $this->con_league->prepare(
            "SELECT id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index
             FROM h2h_match WHERE season_id = :s ORDER BY sort_index ASC"
        );
        $mq->execute([':s' => $seasonId]);
        $allMatches = $mq->fetchAll(PDO::FETCH_ASSOC);

        // Collect all team_ids and matchday_ids referenced
        $allTeamIds     = [];
        $allMatchdayIds = [];
        foreach ($allMatches as $m) {
            $allTeamIds[]     = $m['home_team_id'];
            $allTeamIds[]     = $m['away_team_id'];
            $allMatchdayIds[] = $m['matchday_id'];
        }
        foreach ($groupTeamMap as $teamIds) {
            foreach ($teamIds as $tid) $allTeamIds[] = $tid;
        }
        $allTeamIds     = array_values(array_unique($allTeamIds));
        $allMatchdayIds = array_values(array_unique($allMatchdayIds));

        // Load team info (league DB)
        $teamMap = [];
        if (!empty($allTeamIds)) {
            $ph = implode(',', array_fill(0, count($allTeamIds), '?'));
            $tq = $this->con_league->prepare(
                "SELECT t.id, t.team_name, t.color_primary AS color, t.color_secondary, t.season_id,
                        m.id AS manager_id, m.manager_name
                 FROM team t JOIN manager m ON m.id = t.manager_id WHERE t.id IN ($ph)"
            );
            $tq->execute($allTeamIds);
            foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $t['color']           = $this->resolveColor($t['color']);
                $t['color_secondary'] = $this->resolveColor($t['color_secondary']);
                $teamMap[$t['id']]    = $t;
            }
        }

        // Load matchday info (global DB)
        $matchdayMap = [];
        if (!empty($allMatchdayIds)) {
            $ph = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $mdq = $this->con->prepare(
                "SELECT id, number, kickoff_date, completed FROM matchday WHERE id IN ($ph)"
            );
            $mdq->execute($allMatchdayIds);
            foreach ($mdq->fetchAll(PDO::FETCH_ASSOC) as $md) {
                $matchdayMap[$md['id']] = $md;
            }
        }

        // Load team_rating for all team+matchday combinations (league DB)
        $ratingMap = [];
        if (!empty($allTeamIds) && !empty($allMatchdayIds)) {
            $phT = implode(',', array_fill(0, count($allTeamIds), '?'));
            $phM = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $rq  = $this->con_league->prepare(
                "SELECT team_id, matchday_id, goals, assists, sds_defender, invalid
                 FROM team_rating WHERE team_id IN ($phT) AND matchday_id IN ($phM)"
            );
            $rq->execute(array_merge($allTeamIds, $allMatchdayIds));
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ratingMap[$r['team_id']][$r['matchday_id']] = [
                    'goals'        => $r['goals'] !== null ? (int) $r['goals'] : null,
                    'assists'      => (int) ($r['assists'] ?? 0),
                    'sds_defender' => (int) ($r['sds_defender'] ?? 0),
                    'invalid'      => (bool) $r['invalid'],
                ];
            }
        }

        // Build previous-season rank map (manager_id → rank) as tiebreaker for standings
        // Teams are per-season, so summing team_rating for prev-season team IDs gives correct totals
        $prevRankByManager = [];
        $prevSeasonQ = $this->con->prepare(
            "SELECT id FROM season
             WHERE start_date < (SELECT start_date FROM season WHERE id = :s)
             ORDER BY start_date DESC LIMIT 1"
        );
        $prevSeasonQ->execute([':s' => $seasonId]);
        $prevSeasonId = $prevSeasonQ->fetchColumn();
        if ($prevSeasonId) {
            $ptQ = $this->con_league->prepare(
                "SELECT t.id, t.manager_id, COALESCE(SUM(tr.points), 0) AS total_points
                 FROM team t
                 LEFT JOIN team_rating tr ON tr.team_id = t.id
                 WHERE t.season_id = :s
                 GROUP BY t.id, t.manager_id"
            );
            $ptQ->execute([':s' => $prevSeasonId]);
            $managerPoints = [];
            foreach ($ptQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $managerPoints[$row['manager_id']] = (int) $row['total_points'];
            }
            arsort($managerPoints);
            $rank = 1;
            foreach ($managerPoints as $managerId => $_) {
                $prevRankByManager[$managerId] = $rank++;
            }
        }

        // Enrich matches with result data
        $enrichMatch = function (array $m) use ($teamMap, $matchdayMap, $ratingMap): array {
            $homeRating = $ratingMap[$m['home_team_id']][$m['matchday_id']] ?? null;
            $awayRating = $ratingMap[$m['away_team_id']][$m['matchday_id']] ?? null;
            $md         = $matchdayMap[$m['matchday_id']] ?? null;
            $homeTeam   = $teamMap[$m['home_team_id']] ?? null;
            $awayTeam   = $teamMap[$m['away_team_id']] ?? null;
            return [
                'id'              => $m['id'],
                'phase'           => $m['phase'],
                'leg'             => (int) $m['leg'],
                'sort_index'      => (int) $m['sort_index'],
                'group_id'        => $m['group_id'],
                'home_team_id'    => $m['home_team_id'],
                'home_team_name'  => $homeTeam['team_name'] ?? null,
                'home_color'      => $homeTeam['color'] ?? null,
                'home_manager'    => $homeTeam['manager_name'] ?? null,
                'away_team_id'    => $m['away_team_id'],
                'away_team_name'  => $awayTeam['team_name'] ?? null,
                'away_color'      => $awayTeam['color'] ?? null,
                'away_manager'    => $awayTeam['manager_name'] ?? null,
                'matchday_id'     => $m['matchday_id'],
                'matchday_number' => $md ? (int) $md['number'] : null,
                'kickoff_date'    => $md['kickoff_date'] ?? null,
                'completed'       => isset($md['completed']) ? (bool) $md['completed'] : false,
                'home_goals'        => $homeRating !== null && $homeRating['goals'] !== null
                                        ? max(0, $homeRating['goals'] + intdiv((int)($homeRating['assists'] ?? 0), 3) - ($awayRating['sds_defender'] ?? 0)) : null,
                'away_goals'        => $awayRating !== null && $awayRating['goals'] !== null
                                        ? max(0, $awayRating['goals'] + intdiv((int)($awayRating['assists'] ?? 0), 3) - ($homeRating['sds_defender'] ?? 0)) : null,
                'home_assists'      => (int) ($homeRating['assists'] ?? 0),
                'away_assists'      => (int) ($awayRating['assists'] ?? 0),
                'home_sds_defender' => (int) ($homeRating['sds_defender'] ?? 0),
                'away_sds_defender' => (int) ($awayRating['sds_defender'] ?? 0),
            ];
        };

        // Build group structures with standings
        $groupMatchesByGroup = [];
        $knockoutMatches     = [];
        foreach ($allMatches as $m) {
            if ($m['phase'] === 'group' && $m['group_id']) {
                $groupMatchesByGroup[$m['group_id']][] = $enrichMatch($m);
            } else {
                $knockoutMatches[] = $enrichMatch($m);
            }
        }

        $result = ['groups' => [], 'knockout_matches' => $knockoutMatches];

        foreach ($groups as $g) {
            $teamIds      = $groupTeamMap[$g['id']] ?? [];
            $groupMatches = $groupMatchesByGroup[$g['id']] ?? [];

            // Compute standings
            $standing = [];
            foreach ($teamIds as $tid) {
                $standing[$tid] = ['team_id' => $tid, 'w' => 0, 'd' => 0, 'l' => 0, 'pts' => 0, 'goals_for' => 0, 'goals_against' => 0];
            }

            foreach ($groupMatches as $m) {
                $hp = $m['home_goals'];
                $ap = $m['away_goals'];
                if ($hp === null || $ap === null) continue;

                if (!isset($standing[$m['home_team_id']])) {
                    $standing[$m['home_team_id']] = ['team_id' => $m['home_team_id'], 'w' => 0, 'd' => 0, 'l' => 0, 'pts' => 0, 'goals_for' => 0, 'goals_against' => 0];
                }
                if (!isset($standing[$m['away_team_id']])) {
                    $standing[$m['away_team_id']] = ['team_id' => $m['away_team_id'], 'w' => 0, 'd' => 0, 'l' => 0, 'pts' => 0, 'goals_for' => 0, 'goals_against' => 0];
                }

                $standing[$m['home_team_id']]['goals_for']     += $hp;
                $standing[$m['home_team_id']]['goals_against']  += $ap;
                $standing[$m['away_team_id']]['goals_for']     += $ap;
                $standing[$m['away_team_id']]['goals_against']  += $hp;

                if ($hp > $ap) {
                    $standing[$m['home_team_id']]['w']++;
                    $standing[$m['home_team_id']]['pts'] += 3;
                    $standing[$m['away_team_id']]['l']++;
                } elseif ($hp === $ap) {
                    $standing[$m['home_team_id']]['d']++;
                    $standing[$m['home_team_id']]['pts']++;
                    $standing[$m['away_team_id']]['d']++;
                    $standing[$m['away_team_id']]['pts']++;
                } else {
                    $standing[$m['away_team_id']]['w']++;
                    $standing[$m['away_team_id']]['pts'] += 3;
                    $standing[$m['home_team_id']]['l']++;
                }
            }

            // Enrich standings with team info + sort
            $standingList = array_values($standing);
            foreach ($standingList as &$s) {
                $t            = $teamMap[$s['team_id']] ?? null;
                $s['team_name']    = $t['team_name'] ?? null;
                $s['color']        = $t['color'] ?? null;
                $s['manager_name'] = $t['manager_name'] ?? null;
            }
            unset($s);
            usort($standingList, function ($a, $b) use ($teamMap, $prevRankByManager) {
                if ($a['pts'] !== $b['pts'])         return $b['pts'] <=> $a['pts'];
                $aDiff = $a['goals_for'] - $a['goals_against'];
                $bDiff = $b['goals_for'] - $b['goals_against'];
                if ($aDiff !== $bDiff)               return $bDiff <=> $aDiff;
                if ($a['goals_for'] !== $b['goals_for']) return $b['goals_for'] <=> $a['goals_for'];
                $aRank = $prevRankByManager[$teamMap[$a['team_id']]['manager_id'] ?? ''] ?? PHP_INT_MAX;
                $bRank = $prevRankByManager[$teamMap[$b['team_id']]['manager_id'] ?? ''] ?? PHP_INT_MAX;
                if ($aRank !== $bRank)               return $aRank <=> $bRank;
                return strcmp($a['team_name'] ?? '', $b['team_name'] ?? '');
            });

            // Enrich group teams list
            $groupTeams = array_values(array_filter(
                array_map(fn($tid) => isset($teamMap[$tid]) ? [
                    'id'           => $tid,
                    'team_name'    => $teamMap[$tid]['team_name'],
                    'color'        => $teamMap[$tid]['color'],
                    'manager_name' => $teamMap[$tid]['manager_name'],
                ] : null, $teamIds)
            ));

            $result['groups'][] = [
                'id'         => $g['id'],
                'name'       => $g['name'],
                'sort_index' => (int) $g['sort_index'],
                'teams'      => $teamIds,
                'standings'  => $standingList,
                'matches'    => $groupMatches,
            ];
        }

        return $result;
    }

    public function getH2HMatchDetail(string $matchId): array|false
    {
        // Load match
        $mq = $this->con_league->prepare(
            "SELECT id, season_id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index
             FROM h2h_match WHERE id = :id LIMIT 1"
        );
        $mq->execute([':id' => $matchId]);
        $match = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$match) return false;

        // Load both teams
        $tq = $this->con_league->prepare(
            "SELECT t.id, t.team_name, t.color_primary AS color, t.color_secondary, t.season_id,
                    m.id AS manager_id, m.manager_name, m.alias
             FROM team t JOIN manager m ON m.id = t.manager_id WHERE t.id IN (?, ?)"
        );
        $tq->execute([$match['home_team_id'], $match['away_team_id']]);
        $teamsRaw = $tq->fetchAll(PDO::FETCH_ASSOC);
        $teamMap  = [];
        foreach ($teamsRaw as $t) {
            $t['color']           = $this->resolveColor($t['color']);
            $t['color_secondary'] = $this->resolveColor($t['color_secondary']);
            $teamMap[$t['id']]    = $t;
        }

        // Load matchday (global DB)
        $mdq = $this->con->prepare(
            "SELECT id, number, kickoff_date, start_date, completed FROM matchday WHERE id = :id LIMIT 1"
        );
        $mdq->execute([':id' => $match['matchday_id']]);
        $matchday = $mdq->fetch(PDO::FETCH_ASSOC);

        // Load team_rating for both teams on this matchday (league DB)
        $rq = $this->con_league->prepare(
            "SELECT team_id, points, max_points, goals, sds_defender, assists, invalid
             FROM team_rating WHERE team_id IN (?, ?) AND matchday_id = ?"
        );
        $rq->execute([$match['home_team_id'], $match['away_team_id'], $match['matchday_id']]);
        $ratingMap = [];
        foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingMap[$r['team_id']] = $r;
        }

        // Build lineup for each team
        $seasonId = $match['season_id'];
        $buildLineup = function (string $teamId) use ($match, $seasonId, $matchday): array {
            if (!$matchday) return ['nominated' => [], 'bench' => []];

            $lq = $this->con_league->prepare(
                "SELECT player_id, nominated, position_index FROM team_lineup
                 WHERE team_id = :tid AND matchday_id = :mid"
            );
            $lq->execute([':tid' => $teamId, ':mid' => $match['matchday_id']]);
            $entries = $lq->fetchAll(PDO::FETCH_ASSOC);
            if (empty($entries)) return ['nominated' => [], 'bench' => []];

            $playerIds = array_column($entries, 'player_id');
            $ph        = implode(',', array_fill(0, count($playerIds), '?'));

            // Player info (global DB)
            $pq = $this->con->prepare(
                "SELECT p.id, p.displayname,
                        pis.position, pis.price, pis.photo_uploaded,
                        pis.season_id AS photo_season_id
                 FROM player p
                 LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ?
                 WHERE p.id IN ($ph)"
            );
            $pq->execute(array_merge([$seasonId], $playerIds));
            $playerMap = [];
            foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $playerMap[$p['id']] = $p;
            }

            // Player club for logo (global DB via player_in_club, latest active)
            $clubQ = $this->con->prepare(
                "SELECT pic.player_id, pic.club_id, c.logo_uploaded AS club_logo_uploaded
                 FROM player_in_club pic
                 JOIN club c ON c.id = pic.club_id
                 WHERE pic.player_id IN ($ph) AND pic.to_date IS NULL"
            );
            $clubQ->execute($playerIds);
            $clubMap = [];
            foreach ($clubQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $clubMap[$row['player_id']] = $row;
            }

            // Player ratings (global DB)
            $rq = $this->con->prepare(
                "SELECT player_id, grade, participation, points, goals, assists, clean_sheet,
                        sds, red_card, yellow_red_card
                 FROM player_rating WHERE matchday_id = ? AND player_id IN ($ph)"
            );
            $rq->execute(array_merge([$match['matchday_id']], $playerIds));
            $ratingMap = [];
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ratingMap[$r['player_id']] = $r;
            }

            $posOrder  = ['GOALKEEPER' => 0, 'DEFENDER' => 1, 'MIDFIELDER' => 2, 'FORWARD' => 3];
            $nominated = [];
            $bench     = [];

            foreach ($entries as $e) {
                $p   = $playerMap[$e['player_id']] ?? ['id' => $e['player_id'], 'displayname' => '?', 'position' => null];
                $r   = $ratingMap[$e['player_id']] ?? [];
                $cl  = $clubMap[$e['player_id']] ?? null;
                $row = [
                    'player_id'          => $e['player_id'],
                    'displayname'        => $p['displayname'],
                    'position'           => $p['position'] ?? null,
                    'photo_uploaded'     => (bool) ($p['photo_uploaded'] ?? false),
                    'photo_season_id'    => $p['photo_season_id'] ?? null,
                    'club_id'            => $cl['club_id'] ?? null,
                    'club_logo_uploaded' => (bool) ($cl['club_logo_uploaded'] ?? false),
                    'nominated'          => (bool) $e['nominated'],
                    'position_index'     => $e['position_index'],
                    'grade'              => $r['grade'] ?? null,
                    'points'             => isset($r['points']) ? (int) $r['points'] : null,
                    'goals'              => (int) ($r['goals'] ?? 0),
                    'assists'            => (int) ($r['assists'] ?? 0),
                    'clean_sheet'        => (int) ($r['clean_sheet'] ?? 0),
                    'sds'                => (int) ($r['sds'] ?? 0),
                    'red_card'           => (int) ($r['red_card'] ?? 0),
                    'yellow_red_card'    => (int) ($r['yellow_red_card'] ?? 0),
                    'participation'      => $r['participation'] ?? null,
                ];
                if ($e['nominated']) {
                    $nominated[] = $row;
                } else {
                    $bench[] = $row;
                }
            }

            $sort = fn($a, $b) =>
                ($posOrder[$a['position'] ?? ''] ?? 9) <=> ($posOrder[$b['position'] ?? ''] ?? 9)
                ?: ($a['position_index'] ?? 99) <=> ($b['position_index'] ?? 99);

            usort($nominated, $sort);
            usort($bench, $sort);
            return ['nominated' => $nominated, 'bench' => $bench];
        };

        $homeLineup = $buildLineup($match['home_team_id']);
        $awayLineup = $buildLineup($match['away_team_id']);
        $homeTeam   = $teamMap[$match['home_team_id']] ?? null;
        $awayTeam   = $teamMap[$match['away_team_id']] ?? null;
        $homeRating = $ratingMap[$match['home_team_id']] ?? null;
        $awayRating = $ratingMap[$match['away_team_id']] ?? null;

        return [
            'match'        => [
                'id'             => $match['id'],
                'phase'          => $match['phase'],
                'leg'            => (int) $match['leg'],
                'group_id'       => $match['group_id'],
                'season_id'      => $match['season_id'],
            ],
            'matchday'     => $matchday ?: null,
            'home_team'    => $homeTeam,
            'away_team'    => $awayTeam,
            'home_rating'  => $homeRating ? [
                'points'        => (int) $homeRating['points'],
                'goals'         => max(0, (int) $homeRating['goals'] + intdiv((int) $homeRating['assists'], 3) - (int) ($awayRating['sds_defender'] ?? 0)),
                'assists'       => (int) $homeRating['assists'],
                'sds_defender'  => (int) ($homeRating['sds_defender'] ?? 0),
            ] : null,
            'away_rating'  => $awayRating ? [
                'points'        => (int) $awayRating['points'],
                'goals'         => max(0, (int) $awayRating['goals'] + intdiv((int) $awayRating['assists'], 3) - (int) ($homeRating['sds_defender'] ?? 0)),
                'assists'       => (int) $awayRating['assists'],
                'sds_defender'  => (int) ($awayRating['sds_defender'] ?? 0),
            ] : null,
            'home_lineup'  => $homeLineup['nominated'],
            'home_bench'   => $homeLineup['bench'],
            'away_lineup'  => $awayLineup['nominated'],
            'away_bench'   => $awayLineup['bench'],
        ];
    }

    // --- Group CRUD ---

    public function createH2HGroup(string $id, string $seasonId, string $name, int $sortIndex): void
    {
        $q = $this->con_league->prepare(
            "INSERT INTO h2h_group (id, season_id, name, sort_index) VALUES (:id, :s, :name, :si)"
        );
        $q->execute([':id' => $id, ':s' => $seasonId, ':name' => $name, ':si' => $sortIndex]);
    }

    public function updateH2HGroup(string $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];
        if (isset($fields['name']))       { $sets[] = 'name = :name';         $params[':name'] = $fields['name']; }
        if (isset($fields['sort_index'])) { $sets[] = 'sort_index = :si';     $params[':si']   = (int) $fields['sort_index']; }
        if (empty($sets)) return;
        $q = $this->con_league->prepare("UPDATE h2h_group SET " . implode(', ', $sets) . " WHERE id = :id");
        $q->execute($params);
    }

    public function setGroupTeams(string $groupId, array $teamIds): void
    {
        $dq = $this->con_league->prepare("DELETE FROM h2h_group_team WHERE group_id = :gid");
        $dq->execute([':gid' => $groupId]);
        if (empty($teamIds)) return;
        $iq = $this->con_league->prepare(
            "INSERT INTO h2h_group_team (id, group_id, team_id) VALUES (UUID(), :gid, :tid)"
        );
        foreach ($teamIds as $tid) {
            $iq->execute([':gid' => $groupId, ':tid' => $tid]);
        }
    }

    public function deleteH2HGroup(string $id): void
    {
        $q = $this->con_league->prepare("DELETE FROM h2h_group WHERE id = :id");
        $q->execute([':id' => $id]);
    }

    public function getH2HGroups(string $seasonId): array
    {
        $gq = $this->con_league->prepare(
            "SELECT g.id, g.name, g.sort_index,
                    gt.team_id
             FROM h2h_group g
             LEFT JOIN h2h_group_team gt ON gt.group_id = g.id
             WHERE g.season_id = :s ORDER BY g.sort_index ASC, g.name ASC"
        );
        $gq->execute([':s' => $seasonId]);
        $rows   = $gq->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $r) {
            if (!isset($groups[$r['id']])) {
                $groups[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'sort_index' => (int) $r['sort_index'], 'teams' => []];
            }
            if ($r['team_id']) $groups[$r['id']]['teams'][] = $r['team_id'];
        }
        return array_values($groups);
    }

    // --- Match CRUD ---

    public function createH2HMatch(
        string $id, string $seasonId, string $phase, int $leg,
        string $homeTeamId, string $awayTeamId, string $matchdayId,
        ?string $groupId, int $sortIndex
    ): void {
        $q = $this->con_league->prepare(
            "INSERT INTO h2h_match (id, season_id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index)
             VALUES (:id, :s, :phase, :leg, :home, :away, :md, :gid, :si)"
        );
        $q->execute([
            ':id'    => $id,
            ':s'     => $seasonId,
            ':phase' => $phase,
            ':leg'   => $leg,
            ':home'  => $homeTeamId,
            ':away'  => $awayTeamId,
            ':md'    => $matchdayId,
            ':gid'   => $groupId,
            ':si'    => $sortIndex,
        ]);
    }

    public function updateH2HMatch(string $id, array $fields): void
    {
        $sets   = [];
        $params = [':id' => $id];
        if (isset($fields['home_team_id'])) { $sets[] = 'home_team_id = :home';  $params[':home']  = $fields['home_team_id']; }
        if (isset($fields['away_team_id'])) { $sets[] = 'away_team_id = :away';  $params[':away']  = $fields['away_team_id']; }
        if (isset($fields['matchday_id']))  { $sets[] = 'matchday_id = :md';     $params[':md']    = $fields['matchday_id']; }
        if (isset($fields['group_id']))     { $sets[] = 'group_id = :gid';       $params[':gid']   = $fields['group_id']; }
        if (isset($fields['sort_index']))   { $sets[] = 'sort_index = :si';      $params[':si']    = (int) $fields['sort_index']; }
        if (empty($sets)) return;
        $q = $this->con_league->prepare("UPDATE h2h_match SET " . implode(', ', $sets) . " WHERE id = :id");
        $q->execute($params);
    }

    public function deleteH2HMatch(string $id): void
    {
        $q = $this->con_league->prepare("DELETE FROM h2h_match WHERE id = :id");
        $q->execute([':id' => $id]);
    }

    public function generateH2HTournament(string $leagueId, string $seasonId): array
    {
        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        try {
            $con = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$league['db_name']};charset=utf8",
                $_ENV['DB_USER'], $_ENV['DB_PASSWORD']
            );
            $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $existQ = $con->prepare("SELECT COUNT(*) FROM h2h_group WHERE season_id = :s");
        $existQ->execute([':s' => $seasonId]);
        if ((int) $existQ->fetchColumn() > 0) {
            http_response_code(409);
            return ['status' => false, 'message' => 'H2H-Turnier für diese Saison bereits vorhanden'];
        }

        $teamsQ = $con->prepare(
            "SELECT t.id, t.manager_id, t.team_name FROM team t WHERE t.season_id = :s ORDER BY t.team_name ASC"
        );
        $teamsQ->execute([':s' => $seasonId]);
        $teams = $teamsQ->fetchAll(PDO::FETCH_ASSOC);

        if (count($teams) !== 12) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Genau 12 Teams benötigt, gefunden: ' . count($teams)];
        }

        $prevSeasonQ = $this->con->prepare(
            "SELECT id FROM season
             WHERE start_date < (SELECT start_date FROM season WHERE id = :s)
             ORDER BY start_date DESC LIMIT 1"
        );
        $prevSeasonQ->execute([':s' => $seasonId]);
        $prevSeasonId = $prevSeasonQ->fetchColumn();

        $prevPoints = [];
        if ($prevSeasonId) {
            $ptQ = $con->prepare(
                "SELECT t.manager_id, COALESCE(SUM(tr.points), 0) AS total_points
                 FROM team t LEFT JOIN team_rating tr ON tr.team_id = t.id
                 WHERE t.season_id = :s GROUP BY t.manager_id"
            );
            $ptQ->execute([':s' => $prevSeasonId]);
            foreach ($ptQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $prevPoints[$row['manager_id']] = (int) $row['total_points'];
            }
        }

        usort($teams, function ($a, $b) use ($prevPoints) {
            $aP = $prevPoints[$a['manager_id']] ?? -1;
            $bP = $prevPoints[$b['manager_id']] ?? -1;
            if ($aP !== $bP) return $bP <=> $aP;
            return strcmp($a['team_name'], $b['team_name']);
        });

        // Snake-seed: rank 1-4 → A-D slot 0, rank 5-8 → D-A slot 1, rank 9-12 → A-D slot 2
        $snakeMap = [
            [0, 0], [1, 0], [2, 0], [3, 0],
            [3, 1], [2, 1], [1, 1], [0, 1],
            [0, 2], [1, 2], [2, 2], [3, 2],
        ];
        $groupSlots = [[], [], [], []];
        foreach ($teams as $i => $team) {
            [$gi, $si] = $snakeMap[$i];
            $groupSlots[$gi][$si] = $team['id'];
        }

        $mdQ = $this->con->prepare(
            "SELECT id, number FROM matchday WHERE season_id = :s ORDER BY number ASC"
        );
        $mdQ->execute([':s' => $seasonId]);
        $matchdays = [];
        foreach ($mdQ->fetchAll(PDO::FETCH_ASSOC) as $md) {
            $matchdays[(int) $md['number']] = $md['id'];
        }

        if (count($matchdays) < 18) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Mindestens 18 Spieltage benötigt, gefunden: ' . count($matchdays)];
        }

        // [matchday_number, group_index, leg, home_slot_index, away_slot_index]
        $template = [
            [1,  0, 1, 0, 1], [2,  1, 1, 0, 1], [2,  2, 1, 0, 1], [3,  3, 1, 0, 1],
            [4,  1, 1, 1, 2], [5,  2, 1, 1, 2], [5,  3, 1, 1, 2], [6,  0, 1, 1, 2],
            [7,  2, 1, 2, 0], [8,  3, 1, 2, 0], [8,  0, 1, 2, 0], [9,  1, 1, 2, 0],
            [10, 3, 2, 1, 0], [11, 0, 2, 1, 0], [11, 1, 2, 1, 0], [12, 2, 2, 1, 0],
            [13, 0, 2, 2, 1], [14, 1, 2, 2, 1], [14, 2, 2, 2, 1], [15, 3, 2, 2, 1],
            [16, 1, 2, 0, 2], [17, 2, 2, 0, 2], [17, 3, 2, 0, 2], [18, 0, 2, 0, 2],
        ];

        $groupNames = ['Gruppe A', 'Gruppe B', 'Gruppe C', 'Gruppe D'];
        $groupIds   = [];
        $stmtGroup  = $con->prepare(
            "INSERT INTO h2h_group (id, season_id, name, sort_index) VALUES (?, ?, ?, ?)"
        );
        $stmtGT = $con->prepare(
            "INSERT INTO h2h_group_team (id, group_id, team_id) VALUES (UUID(), ?, ?)"
        );
        for ($gi = 0; $gi < 4; $gi++) {
            $gid = $con->query("SELECT UUID()")->fetchColumn();
            $groupIds[$gi] = $gid;
            $stmtGroup->execute([$gid, $seasonId, $groupNames[$gi], $gi]);
            foreach ($groupSlots[$gi] as $teamId) {
                $stmtGT->execute([$gid, $teamId]);
            }
        }

        $stmtMatch = $con->prepare(
            "INSERT INTO h2h_match (id, season_id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index)
             VALUES (UUID(), ?, 'group', ?, ?, ?, ?, ?, ?)"
        );
        $created = 0;
        foreach ($template as $si => [$mdNum, $gi, $leg, $homeSlot, $awaySlot]) {
            $matchdayId = $matchdays[$mdNum] ?? null;
            if (!$matchdayId) continue;
            $stmtMatch->execute([
                $seasonId, $leg,
                $groupSlots[$gi][$homeSlot],
                $groupSlots[$gi][$awaySlot],
                $matchdayId, $groupIds[$gi], $si,
            ]);
            $created++;
        }

        // ── Notifications ─────────────────────────────────────────────────────

        $teamNameMap = [];
        foreach ($teams as $t) {
            $teamNameMap[$t['id']] = $t['team_name'];
        }

        // General: all 4 groups with their teams
        $generalMsg = '';
        for ($gi = 0; $gi < 4; $gi++) {
            $names       = array_map(fn($tid) => $teamNameMap[$tid] ?? '?', $groupSlots[$gi]);
            $generalMsg .= $groupNames[$gi] . ': ' . implode(', ', $names) . "\n";
        }
        $generalMsg = trim($generalMsg);

        $allMgrsQ = $con->prepare("SELECT id FROM manager WHERE status = 'active'");
        $allMgrsQ->execute();
        $allManagerIds = $allMgrsQ->fetchAll(PDO::FETCH_COLUMN);

        $notifStmt = $con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message) VALUES (UUID(), ?, ?, ?)"
        );
        foreach ($allManagerIds as $mid) {
            $notifStmt->execute([$mid, 'H2H-Gruppenphase ausgelost', $generalMsg]);
        }

        // Individual: each manager's own 4 matches
        $teamMatchList = [];
        foreach ($template as [$mdNum, $gi, $leg, $homeSlot, $awaySlot]) {
            $homeId = $groupSlots[$gi][$homeSlot] ?? null;
            $awayId = $groupSlots[$gi][$awaySlot] ?? null;
            if (!$homeId || !$awayId) continue;
            $teamMatchList[$homeId][] = [$mdNum, $teamNameMap[$awayId] ?? '?', true];
            $teamMatchList[$awayId][] = [$mdNum, $teamNameMap[$homeId] ?? '?', false];
        }
        $indivStmt = $con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message) VALUES (UUID(), ?, ?, ?)"
        );
        foreach ($teams as $team) {
            $matches = $teamMatchList[$team['id']] ?? [];
            if (empty($matches)) continue;
            usort($matches, fn($a, $b) => $a[0] <=> $b[0]);
            $msg = '';
            foreach ($matches as [$mdNum, $oppName, $isHome]) {
                $loc  = $isHome ? 'Heim' : 'Auswärts';
                $msg .= "Spieltag $mdNum – $oppName ($loc)\n";
            }
            $indivStmt->execute([$team['manager_id'], 'Deine H2H-Gruppenspiele', trim($msg)]);
        }

        return ['status' => true, 'groups' => 4, 'matches' => $created];
    }

    public function drawH2HQuarterfinals(string $leagueId, string $seasonId): array
    {
        // Check matchday 18 is completed
        $md18q = $this->con->prepare(
            "SELECT completed FROM matchday WHERE season_id = :s AND number = 18 LIMIT 1"
        );
        $md18q->execute([':s' => $seasonId]);
        $md18 = $md18q->fetch(PDO::FETCH_ASSOC);
        if (!$md18 || !$md18['completed']) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Spieltag 18 ist noch nicht abgeschlossen'];
        }

        // Open league connection
        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }
        try {
            $con = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$league['db_name']};charset=utf8",
                $_ENV['DB_USER'], $_ENV['DB_PASSWORD']
            );
            $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        // Prevent duplicate draw
        $existQ = $con->prepare("SELECT COUNT(*) FROM h2h_match WHERE season_id = :s AND phase = 'quarterfinal'");
        $existQ->execute([':s' => $seasonId]);
        if ((int) $existQ->fetchColumn() > 0) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Viertelfinale bereits vorhanden'];
        }

        // Load groups sorted by sort_index (A=0, B=1, C=2, D=3)
        $gq = $con->prepare(
            "SELECT id, name, sort_index FROM h2h_group WHERE season_id = :s ORDER BY sort_index ASC, name ASC"
        );
        $gq->execute([':s' => $seasonId]);
        $groups = $gq->fetchAll(PDO::FETCH_ASSOC);
        if (count($groups) < 4) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Mindestens 4 Gruppen erforderlich'];
        }

        // Load group-team assignments
        $groupIds = array_column($groups, 'id');
        $ph = implode(',', array_fill(0, count($groupIds), '?'));
        $gtq = $con->prepare("SELECT group_id, team_id FROM h2h_group_team WHERE group_id IN ($ph)");
        $gtq->execute($groupIds);
        $groupTeamMap = [];
        foreach ($gtq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupTeamMap[$row['group_id']][] = $row['team_id'];
        }

        // Load group-phase matches
        $mq = $con->prepare(
            "SELECT home_team_id, away_team_id, matchday_id, group_id
             FROM h2h_match WHERE season_id = :s AND phase = 'group'"
        );
        $mq->execute([':s' => $seasonId]);
        $groupMatches = $mq->fetchAll(PDO::FETCH_ASSOC);

        // Load ratings
        $allTeamIds     = array_unique(array_merge(
            array_column($groupMatches, 'home_team_id'),
            array_column($groupMatches, 'away_team_id')
        ));
        $allMatchdayIds = array_values(array_unique(array_column($groupMatches, 'matchday_id')));
        $ratingMap      = [];
        if (!empty($allTeamIds) && !empty($allMatchdayIds)) {
            $phT = implode(',', array_fill(0, count($allTeamIds), '?'));
            $phM = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $rq  = $con->prepare(
                "SELECT team_id, matchday_id, goals, assists, sds_defender
                 FROM team_rating WHERE team_id IN ($phT) AND matchday_id IN ($phM)"
            );
            $rq->execute(array_merge(array_values($allTeamIds), $allMatchdayIds));
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ratingMap[$r['team_id']][$r['matchday_id']] = [
                    'goals'        => $r['goals'] !== null ? (int) $r['goals'] : null,
                    'assists'      => (int) ($r['assists'] ?? 0),
                    'sds_defender' => (int) ($r['sds_defender'] ?? 0),
                ];
            }
        }

        // Compute standings per group
        $slots = []; // indexed by group sort_index: 0=A, 1=B, 2=C, 3=D
        foreach ($groups as $i => $g) {
            $gid      = $g['id'];
            $teamIds  = $groupTeamMap[$gid] ?? [];
            $standing = [];
            foreach ($teamIds as $tid) {
                $standing[$tid] = ['team_id' => $tid, 'pts' => 0, 'goals_for' => 0, 'goals_against' => 0];
            }
            foreach ($groupMatches as $m) {
                if ($m['group_id'] !== $gid) continue;
                $hR = $ratingMap[$m['home_team_id']][$m['matchday_id']] ?? null;
                $aR = $ratingMap[$m['away_team_id']][$m['matchday_id']] ?? null;
                if (!$hR || !$aR || $hR['goals'] === null || $aR['goals'] === null) continue;
                $hp = max(0, $hR['goals'] + intdiv($hR['assists'], 3) - $aR['sds_defender']);
                $ap = max(0, $aR['goals'] + intdiv($aR['assists'], 3) - $hR['sds_defender']);
                if (!isset($standing[$m['home_team_id']])) $standing[$m['home_team_id']] = ['team_id' => $m['home_team_id'], 'pts' => 0, 'goals_for' => 0, 'goals_against' => 0];
                if (!isset($standing[$m['away_team_id']])) $standing[$m['away_team_id']] = ['team_id' => $m['away_team_id'], 'pts' => 0, 'goals_for' => 0, 'goals_against' => 0];
                $standing[$m['home_team_id']]['goals_for']     += $hp;
                $standing[$m['home_team_id']]['goals_against'] += $ap;
                $standing[$m['away_team_id']]['goals_for']     += $ap;
                $standing[$m['away_team_id']]['goals_against'] += $hp;
                if ($hp > $ap)       { $standing[$m['home_team_id']]['pts'] += 3; }
                elseif ($hp === $ap) { $standing[$m['home_team_id']]['pts']++; $standing[$m['away_team_id']]['pts']++; }
                else                 { $standing[$m['away_team_id']]['pts'] += 3; }
            }
            $list = array_values($standing);
            usort($list, function ($a, $b) {
                if ($a['pts'] !== $b['pts']) return $b['pts'] <=> $a['pts'];
                $aDiff = $a['goals_for'] - $a['goals_against'];
                $bDiff = $b['goals_for'] - $b['goals_against'];
                return $bDiff <=> $aDiff ?: $b['goals_for'] <=> $a['goals_for'];
            });
            if (empty($list[0]) || empty($list[1])) {
                http_response_code(400);
                return ['status' => false, 'message' => "Gruppe {$g['name']}: unvollständige Standings"];
            }
            $slots[$i] = ['first' => $list[0]['team_id'], 'second' => $list[1]['team_id']];
        }

        [$a1, $a2] = [$slots[0]['first'], $slots[0]['second']];
        [$b1, $b2] = [$slots[1]['first'], $slots[1]['second']];
        [$c1, $c2] = [$slots[2]['first'], $slots[2]['second']];
        [$d1, $d2] = [$slots[3]['first'], $slots[3]['second']];

        // Load matchdays 20-27
        $mdQ = $this->con->prepare(
            "SELECT id, number FROM matchday WHERE season_id = :s AND number IN (20,21,22,23,24,25,26,27)"
        );
        $mdQ->execute([':s' => $seasonId]);
        $matchdays = [];
        foreach ($mdQ->fetchAll(PDO::FETCH_ASSOC) as $md) {
            $matchdays[(int) $md['number']] = $md['id'];
        }
        $missing = array_diff([20, 21, 22, 23, 24, 25, 26, 27], array_keys($matchdays));
        if (!empty($missing)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Fehlende Spieltage: ' . implode(', ', $missing)];
        }

        // Fixed bracket
        // [leg, home, away, matchday_number, sort_index]
        $bracket = [
            [1, $a1, $b2, 20, 0],
            [1, $b1, $a2, 21, 1],
            [1, $c1, $d2, 22, 2],
            [1, $d1, $c2, 23, 3],
            [2, $b2, $a1, 24, 0],
            [2, $a2, $b1, 25, 1],
            [2, $d2, $c1, 26, 2],
            [2, $c2, $d1, 27, 3],
        ];

        $stmt = $con->prepare(
            "INSERT INTO h2h_match (id, season_id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index)
             VALUES (UUID(), ?, 'quarterfinal', ?, ?, ?, ?, NULL, ?)"
        );
        foreach ($bracket as [$leg, $home, $away, $mdNum, $si]) {
            $stmt->execute([$seasonId, $leg, $home, $away, $matchdays[$mdNum], $si]);
        }

        // ── Notifications ─────────────────────────────────────────────────────

        $tnQ = $con->prepare("SELECT id, team_name FROM team WHERE id IN (?,?,?,?,?,?,?,?)");
        $tnQ->execute([$a1, $a2, $b1, $b2, $c1, $c2, $d1, $d2]);
        $qfNames = [];
        foreach ($tnQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qfNames[$r['id']] = $r['team_name'];
        }
        $tn = fn(string $id) => $qfNames[$id] ?? '?';

        $qfMsg  = "VF 1: {$tn($a1)} – {$tn($b2)} (Hin: ST20, Rück: ST24)\n";
        $qfMsg .= "VF 2: {$tn($b1)} – {$tn($a2)} (Hin: ST21, Rück: ST25)\n";
        $qfMsg .= "VF 3: {$tn($c1)} – {$tn($d2)} (Hin: ST22, Rück: ST26)\n";
        $qfMsg .= "VF 4: {$tn($d1)} – {$tn($c2)} (Hin: ST23, Rück: ST27)";

        $allMgrsQ = $con->prepare("SELECT id FROM manager WHERE status = 'active'");
        $allMgrsQ->execute();
        $notifStmt = $con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message) VALUES (UUID(), ?, ?, ?)"
        );
        foreach ($allMgrsQ->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $notifStmt->execute([$mid, 'H2H-Viertelfinale ausgelost', $qfMsg]);
        }

        return ['status' => true, 'matches' => 8];
    }

    public function drawH2HSemifinals(string $leagueId, string $seasonId): array
    {
        $md27q = $this->con->prepare(
            "SELECT completed FROM matchday WHERE season_id = :s AND number = 27 LIMIT 1"
        );
        $md27q->execute([':s' => $seasonId]);
        $md27 = $md27q->fetch(PDO::FETCH_ASSOC);
        if (!$md27 || !$md27['completed']) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Spieltag 27 ist noch nicht abgeschlossen'];
        }

        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }
        try {
            $con = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$league['db_name']};charset=utf8",
                $_ENV['DB_USER'], $_ENV['DB_PASSWORD']
            );
            $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $existQ = $con->prepare("SELECT COUNT(*) FROM h2h_match WHERE season_id = :s AND phase = 'semifinal'");
        $existQ->execute([':s' => $seasonId]);
        if ((int) $existQ->fetchColumn() > 0) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Halbfinale bereits vorhanden'];
        }

        $qfQ = $con->prepare(
            "SELECT leg, home_team_id, away_team_id, matchday_id, sort_index
             FROM h2h_match WHERE season_id = :s AND phase = 'quarterfinal'
             ORDER BY sort_index ASC, leg ASC"
        );
        $qfQ->execute([':s' => $seasonId]);
        $qfMatches = $qfQ->fetchAll(PDO::FETCH_ASSOC);

        $qfPairs = [];
        foreach ($qfMatches as $m) {
            $si = (int) $m['sort_index'];
            $qfPairs[$si][(int)$m['leg'] === 1 ? 'leg1' : 'leg2'] = $m;
        }

        $allTeamIds     = array_values(array_unique(array_merge(
            array_column($qfMatches, 'home_team_id'),
            array_column($qfMatches, 'away_team_id')
        )));
        $allMatchdayIds = array_values(array_unique(array_column($qfMatches, 'matchday_id')));

        $ratingMap = [];
        if (!empty($allTeamIds) && !empty($allMatchdayIds)) {
            $phT = implode(',', array_fill(0, count($allTeamIds), '?'));
            $phM = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $rq  = $con->prepare(
                "SELECT team_id, matchday_id, goals, assists, sds_defender
                 FROM team_rating WHERE team_id IN ($phT) AND matchday_id IN ($phM)"
            );
            $rq->execute(array_merge($allTeamIds, $allMatchdayIds));
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ratingMap[$r['team_id']][$r['matchday_id']] = [
                    'goals'        => $r['goals'] !== null ? (int) $r['goals'] : null,
                    'assists'      => (int) ($r['assists'] ?? 0),
                    'sds_defender' => (int) ($r['sds_defender'] ?? 0),
                ];
            }
        }

        $effectiveGoals = function (string $tid, string $oppId, string $mdId) use ($ratingMap): ?int {
            $r   = $ratingMap[$tid][$mdId]   ?? null;
            $opp = $ratingMap[$oppId][$mdId] ?? null;
            if (!$r || $r['goals'] === null) return null;
            return max(0, $r['goals'] + intdiv($r['assists'], 3) - ($opp['sds_defender'] ?? 0));
        };

        $vfWinners = [];
        for ($si = 0; $si <= 3; $si++) {
            if (!isset($qfPairs[$si]['leg1']) || !isset($qfPairs[$si]['leg2'])) {
                http_response_code(400);
                return ['status' => false, 'message' => "Viertelfinale " . ($si + 1) . " unvollständig"];
            }
            $leg1  = $qfPairs[$si]['leg1'];
            $leg2  = $qfPairs[$si]['leg2'];
            $teamA = $leg1['home_team_id'];
            $teamB = $leg1['away_team_id'];
            $a1 = $effectiveGoals($teamA, $teamB, $leg1['matchday_id']);
            $b1 = $effectiveGoals($teamB, $teamA, $leg1['matchday_id']);
            $a2 = $effectiveGoals($teamA, $teamB, $leg2['matchday_id']);
            $b2 = $effectiveGoals($teamB, $teamA, $leg2['matchday_id']);
            if ($a1 === null || $b1 === null || $a2 === null || $b2 === null) {
                http_response_code(400);
                return ['status' => false, 'message' => "Viertelfinale " . ($si + 1) . ": fehlende Bewertungen"];
            }
            $aTotal = $a1 + $a2;
            $bTotal = $b1 + $b2;
            if ($aTotal === $bTotal) {
                http_response_code(400);
                return ['status' => false, 'message' => "Viertelfinale " . ($si + 1) . ": Unentschieden — Sieger kann nicht automatisch ermittelt werden"];
            }
            $vfWinners[$si] = $aTotal > $bTotal ? $teamA : $teamB;
        }

        $mdQ = $this->con->prepare(
            "SELECT id, number FROM matchday WHERE season_id = :s AND number IN (29,30,31,32)"
        );
        $mdQ->execute([':s' => $seasonId]);
        $matchdays = [];
        foreach ($mdQ->fetchAll(PDO::FETCH_ASSOC) as $md) {
            $matchdays[(int) $md['number']] = $md['id'];
        }
        $missing = array_diff([29, 30, 31, 32], array_keys($matchdays));
        if (!empty($missing)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Fehlende Spieltage: ' . implode(', ', $missing)];
        }

        [$vf1, $vf2, $vf3, $vf4] = [$vfWinners[0], $vfWinners[1], $vfWinners[2], $vfWinners[3]];
        // Cross-bracket: VF1 vs VF4 and VF2 vs VF3 to avoid A/B silo
        $bracket = [
            [1, $vf1, $vf4, 29, 0],
            [1, $vf2, $vf3, 30, 1],
            [2, $vf4, $vf1, 31, 0],
            [2, $vf3, $vf2, 32, 1],
        ];

        $stmt = $con->prepare(
            "INSERT INTO h2h_match (id, season_id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index)
             VALUES (UUID(), ?, 'semifinal', ?, ?, ?, ?, NULL, ?)"
        );
        foreach ($bracket as [$leg, $home, $away, $mdNum, $si]) {
            $stmt->execute([$seasonId, $leg, $home, $away, $matchdays[$mdNum], $si]);
        }

        // ── Notifications ─────────────────────────────────────────────────────

        $sfTeamIds = array_values(array_unique([$vf1, $vf2, $vf3, $vf4]));
        $ph        = implode(',', array_fill(0, count($sfTeamIds), '?'));
        $tnQ       = $con->prepare("SELECT id, team_name FROM team WHERE id IN ($ph)");
        $tnQ->execute($sfTeamIds);
        $sfNames = [];
        foreach ($tnQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sfNames[$r['id']] = $r['team_name'];
        }
        $tn = fn(string $id) => $sfNames[$id] ?? '?';

        $sfMsg  = "HF 1: {$tn($vf1)} – {$tn($vf4)} (Hin: ST29, Rück: ST31)\n";
        $sfMsg .= "HF 2: {$tn($vf2)} – {$tn($vf3)} (Hin: ST30, Rück: ST32)";

        $allMgrsQ = $con->prepare("SELECT id FROM manager WHERE status = 'active'");
        $allMgrsQ->execute();
        $notifStmt = $con->prepare(
            "INSERT INTO notification (id, receiver_id, title, message) VALUES (UUID(), ?, ?, ?)"
        );
        foreach ($allMgrsQ->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $notifStmt->execute([$mid, 'H2H-Halbfinale ausgelost', $sfMsg]);
        }

        return ['status' => true, 'matches' => 4];
    }

    public function drawH2HFinal(string $leagueId, string $seasonId): array
    {
        $md32q = $this->con->prepare(
            "SELECT completed FROM matchday WHERE season_id = :s AND number = 32 LIMIT 1"
        );
        $md32q->execute([':s' => $seasonId]);
        $md32 = $md32q->fetch(PDO::FETCH_ASSOC);
        if (!$md32 || !$md32['completed']) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Spieltag 32 ist noch nicht abgeschlossen'];
        }

        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }
        try {
            $con = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$league['db_name']};charset=utf8",
                $_ENV['DB_USER'], $_ENV['DB_PASSWORD']
            );
            $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $existQ = $con->prepare("SELECT COUNT(*) FROM h2h_match WHERE season_id = :s AND phase = 'final'");
        $existQ->execute([':s' => $seasonId]);
        if ((int) $existQ->fetchColumn() > 0) {
            http_response_code(409);
            return ['status' => false, 'message' => 'Finale bereits vorhanden'];
        }

        $sfQ = $con->prepare(
            "SELECT leg, home_team_id, away_team_id, matchday_id, sort_index
             FROM h2h_match WHERE season_id = :s AND phase = 'semifinal'
             ORDER BY sort_index ASC, leg ASC"
        );
        $sfQ->execute([':s' => $seasonId]);
        $sfMatches = $sfQ->fetchAll(PDO::FETCH_ASSOC);

        $sfPairs = [];
        foreach ($sfMatches as $m) {
            $si = (int) $m['sort_index'];
            $sfPairs[$si][(int)$m['leg'] === 1 ? 'leg1' : 'leg2'] = $m;
        }

        $allTeamIds     = array_values(array_unique(array_merge(
            array_column($sfMatches, 'home_team_id'),
            array_column($sfMatches, 'away_team_id')
        )));
        $allMatchdayIds = array_values(array_unique(array_column($sfMatches, 'matchday_id')));

        $ratingMap = [];
        if (!empty($allTeamIds) && !empty($allMatchdayIds)) {
            $phT = implode(',', array_fill(0, count($allTeamIds), '?'));
            $phM = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $rq  = $con->prepare(
                "SELECT team_id, matchday_id, goals, assists, sds_defender
                 FROM team_rating WHERE team_id IN ($phT) AND matchday_id IN ($phM)"
            );
            $rq->execute(array_merge($allTeamIds, $allMatchdayIds));
            foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ratingMap[$r['team_id']][$r['matchday_id']] = [
                    'goals'        => $r['goals'] !== null ? (int) $r['goals'] : null,
                    'assists'      => (int) ($r['assists'] ?? 0),
                    'sds_defender' => (int) ($r['sds_defender'] ?? 0),
                ];
            }
        }

        $effectiveGoals = function (string $tid, string $oppId, string $mdId) use ($ratingMap): ?int {
            $r   = $ratingMap[$tid][$mdId]   ?? null;
            $opp = $ratingMap[$oppId][$mdId] ?? null;
            if (!$r || $r['goals'] === null) return null;
            return max(0, $r['goals'] + intdiv($r['assists'], 3) - ($opp['sds_defender'] ?? 0));
        };

        $sfWinners = [];
        for ($si = 0; $si <= 1; $si++) {
            if (!isset($sfPairs[$si]['leg1']) || !isset($sfPairs[$si]['leg2'])) {
                http_response_code(400);
                return ['status' => false, 'message' => "Halbfinale " . ($si + 1) . " unvollständig"];
            }
            $leg1  = $sfPairs[$si]['leg1'];
            $leg2  = $sfPairs[$si]['leg2'];
            $teamA = $leg1['home_team_id'];
            $teamB = $leg1['away_team_id'];
            $a1 = $effectiveGoals($teamA, $teamB, $leg1['matchday_id']);
            $b1 = $effectiveGoals($teamB, $teamA, $leg1['matchday_id']);
            $a2 = $effectiveGoals($teamA, $teamB, $leg2['matchday_id']);
            $b2 = $effectiveGoals($teamB, $teamA, $leg2['matchday_id']);
            if ($a1 === null || $b1 === null || $a2 === null || $b2 === null) {
                http_response_code(400);
                return ['status' => false, 'message' => "Halbfinale " . ($si + 1) . ": fehlende Bewertungen"];
            }
            $aTotal = $a1 + $a2;
            $bTotal = $b1 + $b2;
            if ($aTotal === $bTotal) {
                http_response_code(400);
                return ['status' => false, 'message' => "Halbfinale " . ($si + 1) . ": Unentschieden — Sieger kann nicht automatisch ermittelt werden"];
            }
            $sfWinners[$si] = $aTotal > $bTotal ? $teamA : $teamB;
        }

        $mdQ = $this->con->prepare(
            "SELECT id FROM matchday WHERE season_id = :s AND number = 34 LIMIT 1"
        );
        $mdQ->execute([':s' => $seasonId]);
        $md34 = $mdQ->fetchColumn();
        if (!$md34) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Spieltag 34 nicht gefunden'];
        }

        $stmt = $con->prepare(
            "INSERT INTO h2h_match (id, season_id, phase, leg, home_team_id, away_team_id, matchday_id, group_id, sort_index)
             VALUES (UUID(), ?, 'final', 1, ?, ?, ?, NULL, 0)"
        );
        $stmt->execute([$seasonId, $sfWinners[0], $sfWinners[1], $md34]);

        return ['status' => true, 'matches' => 1];
    }
}
