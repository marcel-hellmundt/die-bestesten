<?php

trait SaisonvorschauTrait
{
    public function getSaisonvorschau(): array
    {
        $empty = [
            'season_id' => null,
            'previous_season_id' => null,
            'teams' => [],
            'promoted_clubs' => [],
            'promoted_club_teams' => [],
            'special_clubs' => [],
            'special_club_teams' => [],
        ];

        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) return $empty;

        $pq = $this->con->prepare(
            "SELECT id FROM season WHERE start_date < (SELECT start_date FROM season WHERE id = :id) ORDER BY start_date DESC LIMIT 1"
        );
        $pq->execute([':id' => $seasonId]);
        $prevSeasonId = $pq->fetchColumn() ?: null;

        $tq = $this->con_league->prepare(
            "SELECT t.id, t.team_name, t.color_primary AS color, t.color_secondary, t.manager_id, m.manager_name, m.alias
             FROM team t JOIN manager m ON m.id = t.manager_id
             WHERE t.season_id = :s ORDER BY t.team_name"
        );
        $tq->execute([':s' => $seasonId]);
        $teams = $tq->fetchAll(PDO::FETCH_ASSOC);

        if (empty($teams)) {
            $empty['season_id'] = $seasonId;
            $empty['previous_season_id'] = $prevSeasonId;
            return $empty;
        }

        foreach ($teams as &$t) {
            $t['color']           = $this->resolveColor($t['color']);
            $t['color_secondary'] = $this->resolveColor($t['color_secondary']);
        }
        unset($t);

        $teamIds = array_column($teams, 'id');
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $pitQ = $this->con_league->prepare(
            "SELECT team_id, player_id FROM player_in_team WHERE team_id IN ($ph) AND to_matchday_id IS NULL"
        );
        $pitQ->execute($teamIds);

        $teamPlayerIds = array_fill_keys($teamIds, []);
        $allPlayerIds  = [];
        foreach ($pitQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teamPlayerIds[$row['team_id']][] = $row['player_id'];
            $allPlayerIds[] = $row['player_id'];
        }
        $allPlayerIds = array_values(array_unique($allPlayerIds));

        $positions       = [];
        $currentClub     = [];
        $prevPoints      = [];
        $prevRatingCount = [];

        if (!empty($allPlayerIds)) {
            $pp = implode(',', array_fill(0, count($allPlayerIds), '?'));

            $posQ = $this->con->prepare(
                "SELECT player_id, position FROM player_in_season WHERE player_id IN ($pp) AND season_id = ?"
            );
            $posQ->execute([...$allPlayerIds, $seasonId]);
            foreach ($posQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $positions[$r['player_id']] = $r['position'];
            }

            $clubQ = $this->con->prepare(
                "SELECT player_id, club_id FROM player_in_club WHERE player_id IN ($pp) AND to_date IS NULL"
            );
            $clubQ->execute($allPlayerIds);
            foreach ($clubQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $currentClub[$r['player_id']] = $r['club_id'];
            }

            if ($prevSeasonId) {
                $prQ = $this->con->prepare(
                    "SELECT pr.player_id, COALESCE(SUM(pr.points),0) AS pts, COUNT(pr.id) AS cnt
                     FROM player_rating pr
                     WHERE pr.player_id IN ($pp) AND pr.matchday_id IN (SELECT id FROM matchday WHERE season_id = ?)
                     GROUP BY pr.player_id"
                );
                $prQ->execute([...$allPlayerIds, $prevSeasonId]);
                foreach ($prQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $prevPoints[$r['player_id']]      = (int) $r['pts'];
                    $prevRatingCount[$r['player_id']] = (int) $r['cnt'];
                }
            }
        }

        $sqMin = ['GOALKEEPER' => 1, 'DEFENDER' => 5, 'MIDFIELDER' => 5, 'FORWARD' => 3];
        foreach ($teams as &$team) {
            $counts    = ['GOALKEEPER' => 0, 'DEFENDER' => 0, 'MIDFIELDER' => 0, 'FORWARD' => 0];
            $points    = 0;
            $newcomers = 0;
            foreach ($teamPlayerIds[$team['id']] as $pid) {
                $pos = $positions[$pid] ?? null;
                if ($pos && isset($counts[$pos])) $counts[$pos]++;
                $points += $prevPoints[$pid] ?? 0;
                if (($prevRatingCount[$pid] ?? 0) === 0) $newcomers++;
            }
            $valid = true;
            foreach ($sqMin as $pos => $min) {
                if ($counts[$pos] < $min) { $valid = false; break; }
            }
            $team['squad_valid']             = $valid;
            $team['position_counts']         = $counts;
            $team['previous_season_points']  = $points;
            $team['newcomer_count']          = $newcomers;
        }
        unset($team);

        $promotedClubs = [];
        if ($prevSeasonId) {
            $divisionId = $this->getLeagueDivisionId();
            if (!$divisionId) {
                $fq = $this->con->prepare("SELECT id FROM division WHERE level = 1 AND LOWER(country_id) = 'de' LIMIT 1");
                $fq->execute();
                $divisionId = $fq->fetchColumn() ?: null;
            }
            if ($divisionId) {
                $lvlQ = $this->con->prepare("SELECT level FROM division WHERE id = :id LIMIT 1");
                $lvlQ->execute([':id' => $divisionId]);
                $curLevel = $lvlQ->fetchColumn();
                if ($curLevel !== false) {
                    $pcQ = $this->con->prepare(
                        "SELECT c.id, c.name, c.short_name, c.logo_uploaded
                         FROM club_in_season cis_cur
                         JOIN club c ON c.id = cis_cur.club_id
                         JOIN club_in_season cis_prev ON cis_prev.club_id = cis_cur.club_id AND cis_prev.season_id = :prev
                         JOIN division d_prev ON d_prev.id = cis_prev.division_id
                         WHERE cis_cur.season_id = :cur AND cis_cur.division_id = :div AND d_prev.level = :prev_level"
                    );
                    $pcQ->execute([
                        ':prev'       => $prevSeasonId,
                        ':cur'        => $seasonId,
                        ':div'        => $divisionId,
                        ':prev_level' => ((int) $curLevel) + 1,
                    ]);
                    foreach ($pcQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $promotedClubs[] = [
                            'id'            => $r['id'],
                            'name'          => $r['name'],
                            'short_name'    => $r['short_name'],
                            'logo_uploaded' => (bool) $r['logo_uploaded'],
                        ];
                    }
                }
            }
        }

        $specialClubs = [];
        foreach (['RB Leipzig', 'TSG Hoffenheim', 'VfL Wolfsburg'] as $name) {
            $c = $this->findClubByName($name);
            if ($c) {
                $specialClubs[] = [
                    'id'            => $c['id'],
                    'name'          => $c['name'],
                    'logo_uploaded' => $c['logo_uploaded'],
                ];
            }
        }

        return [
            'season_id'            => $seasonId,
            'previous_season_id'   => $prevSeasonId,
            'teams'                => $teams,
            'promoted_clubs'       => $promotedClubs,
            'promoted_club_teams'  => $this->countTeamsByClubIds($teams, $teamPlayerIds, $currentClub, array_column($promotedClubs, 'id')),
            'special_clubs'        => $specialClubs,
            'special_club_teams'   => $this->countTeamsByClubIds($teams, $teamPlayerIds, $currentClub, array_column($specialClubs, 'id')),
        ];
    }

    /**
     * Pro Team zählen, wie viele seiner (bereits geladenen) Kaderspieler aktuell einem der
     * übergebenen Vereine angehören. Nur Teams mit Treffern werden zurückgegeben, absteigend
     * sortiert. Gemeinsame Hilfsfunktion für Aufsteiger- und Fest-Vereine-Karte in
     * getSaisonvorschau().
     */
    private function countTeamsByClubIds(array $teams, array $teamPlayerIds, array $currentClub, array $clubIds): array
    {
        if (empty($clubIds)) return [];
        $clubIdSet = array_flip($clubIds);

        $result = [];
        foreach ($teams as $team) {
            $count = 0;
            foreach ($teamPlayerIds[$team['id']] as $pid) {
                if (isset($clubIdSet[$currentClub[$pid] ?? null])) $count++;
            }
            if ($count > 0) {
                $result[] = [
                    'team_id'         => $team['id'],
                    'team_name'       => $team['team_name'],
                    'color'           => $team['color'],
                    'color_secondary' => $team['color_secondary'],
                    'count'           => $count,
                ];
            }
        }

        usort($result, fn($a, $b) => $b['count'] <=> $a['count']);
        return $result;
    }
}
