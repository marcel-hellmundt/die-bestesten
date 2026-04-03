<?php

trait LeagueTrait
{
    public function getLeagueList(): array
    {
        $query = $this->con->prepare("SELECT * FROM league ORDER BY name ASC");
        $query->execute();
        $leagues = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leagues as &$league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['db_name']);
        }

        return $leagues;
    }

    public function getLeagueById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM league WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        $league = $query->fetch(PDO::FETCH_ASSOC);
        if ($league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['db_name']);
            $league['managers']      = $this->getLeagueManagerList($league['db_name']);
        }
        return $league;
    }

    public function migrateLeagueTeams(string $leagueId): array
    {
        $league = $this->getLeagueById($leagueId);
        if (!$league) return ['status' => false, 'message' => 'Liga nicht gefunden'];

        $conLeague = $this->openLeagueConnection($league['db_name']);
        if (!$conLeague) return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];

        $rows = $this->con_old->query(
            "SELECT team_id, manager_id, season_id, team_name, color FROM team"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conLeague->prepare(
            "INSERT INTO team (id, manager_id, season_id, team_name, color)
             VALUES (:id, :manager_id, :season_id, :team_name, :color)
             ON DUPLICATE KEY UPDATE
               team_name = VALUES(team_name),
               color     = VALUES(color)"
        );

        $migrated = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $color = $row['color'];
            if ($color && !str_starts_with($color, '#')) {
                $color = '#' . $color;
            }
            if ($color && strlen($color) > 7) {
                $skipped++;
                continue;
            }

            $stmt->execute([
                ':id'         => $row['team_id'],
                ':manager_id' => $row['manager_id'],
                ':season_id'  => $row['season_id'],
                ':team_name'  => $row['team_name'],
                ':color'      => $color,
            ]);
            $migrated++;
        }

        // Migrate team_ratings
        $ratingRows = $this->con_old->query(
            "SELECT tr.team_rating_id, tr.team_id, tr.matchday_number,
                    tr.points, tr.max_points, tr.goals, tr.assists,
                    tr.clean_sheet, tr.sds, tr.sds_defender, tr.missed_goals,
                    tr.points_goalkeeper, tr.points_defender,
                    tr.points_midfielder, tr.points_forward,
                    tr.invalid,
                    t.season_id
             FROM team_rating tr
             JOIN team t ON t.team_id = tr.team_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Build matchday lookup: (season_id, number) → id
        $matchdayRows = $this->con->query(
            "SELECT id, season_id, number FROM matchday"
        )->fetchAll(PDO::FETCH_ASSOC);
        $matchdayMap = [];
        foreach ($matchdayRows as $md) {
            $matchdayMap[$md['season_id'] . '_' . $md['number']] = $md['id'];
        }

        // Build season start_date lookup for auto-creating missing matchdays
        $seasonRows = $this->con->query(
            "SELECT id, start_date FROM season"
        )->fetchAll(PDO::FETCH_ASSOC);
        $seasonStartDates = [];
        foreach ($seasonRows as $s) {
            $seasonStartDates[$s['id']] = $s['start_date'];
        }

        $stmtCreateMatchday = $this->con->prepare(
            "INSERT INTO matchday (id, season_id, number, start_date, kickoff_date, completed)
             VALUES (:id, :season_id, :number, :start_date, :kickoff_date, 0)
             ON DUPLICATE KEY UPDATE id = id"
        );

        $stmtRating = $conLeague->prepare(
            "INSERT INTO team_rating (
                id, team_id, matchday_id, points, max_points, goals, assists,
                clean_sheet, sds, sds_defender, missed_goals,
                points_goalkeeper, points_defender, points_midfielder, points_forward, invalid
             ) VALUES (
                :id, :team_id, :matchday_id, :points, :max_points, :goals, :assists,
                :clean_sheet, :sds, :sds_defender, :missed_goals,
                :points_goalkeeper, :points_defender, :points_midfielder, :points_forward, :invalid
             ) ON DUPLICATE KEY UPDATE
                points             = VALUES(points),
                max_points         = VALUES(max_points),
                goals              = VALUES(goals),
                assists            = VALUES(assists),
                clean_sheet        = VALUES(clean_sheet),
                sds                = VALUES(sds),
                sds_defender       = VALUES(sds_defender),
                missed_goals       = VALUES(missed_goals),
                points_goalkeeper  = VALUES(points_goalkeeper),
                points_defender    = VALUES(points_defender),
                points_midfielder  = VALUES(points_midfielder),
                points_forward     = VALUES(points_forward),
                invalid            = VALUES(invalid)"
        );

        $migratedRatings  = 0;
        $createdMatchdays = [];

        foreach ($ratingRows as $row) {
            $key = $row['season_id'] . '_' . $row['matchday_number'];
            $matchdayId = $matchdayMap[$key] ?? null;
            if (!$matchdayId) {
                $newId     = $this->con->query("SELECT UUID()")->fetchColumn();
                $startDate = $seasonStartDates[$row['season_id']] ?? date('Y-m-d');
                $stmtCreateMatchday->execute([
                    ':id'           => $newId,
                    ':season_id'    => $row['season_id'],
                    ':number'       => $row['matchday_number'],
                    ':start_date'   => $startDate,
                    ':kickoff_date' => $startDate . ' 00:00:00',
                ]);
                $matchdayMap[$key] = $newId;
                $matchdayId        = $newId;
                $createdMatchdays[$key] = [
                    'season_id'       => $row['season_id'],
                    'matchday_number' => $row['matchday_number'],
                    'matchday_id'     => $newId,
                ];
            }
            $stmtRating->execute([
                ':id'               => $row['team_rating_id'],
                ':team_id'          => $row['team_id'],
                ':matchday_id'      => $matchdayId,
                ':points'           => $row['points'],
                ':max_points'       => $row['max_points'],
                ':goals'            => $row['goals'],
                ':assists'          => $row['assists'],
                ':clean_sheet'      => $row['clean_sheet'],
                ':sds'              => $row['sds'],
                ':sds_defender'     => $row['sds_defender'],
                ':missed_goals'     => $row['missed_goals'],
                ':points_goalkeeper'=> $row['points_goalkeeper'],
                ':points_defender'  => $row['points_defender'],
                ':points_midfielder'=> $row['points_midfielder'],
                ':points_forward'   => $row['points_forward'],
                ':invalid'          => $row['invalid'],
            ]);
            $migratedRatings++;
        }

        // Migrate award_in_season → team_award
        $migratedAwards = 0;
        try {
            $awardRows = $this->con_old->query(
                "SELECT ais.award_id, ais.team_id
                 FROM award_in_season ais"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Load award UUIDs from global DB (keyed by old award_id if they match, or by name)
            // Old award_id maps directly to global award.id (same UUID assumed after manual seeding)
            $stmtAward = $conLeague->prepare(
                "INSERT INTO team_award (id, team_id, award_id)
                 VALUES (UUID(), :team_id, :award_id)
                 ON DUPLICATE KEY UPDATE award_id = award_id"
            );

            foreach ($awardRows as $row) {
                $stmtAward->execute([
                    ':team_id'  => $row['team_id'],
                    ':award_id' => $row['award_id'],
                ]);
                $migratedAwards++;
            }
        } catch (PDOException) {
            // award_in_season may not exist in older DBs — skip silently
        }

        return [
            'status'          => true,
            'teams'           => ['migrated' => $migrated,        'skipped' => $skipped],
            'team_ratings'    => ['migrated' => $migratedRatings],
            'team_awards'     => ['migrated' => $migratedAwards],
            'matchdays_created' => array_values($createdMatchdays),
        ];
    }

    private function getLeagueManagerCount(string $dbName): int
    {
        try {
            $pdo = $this->openLeagueConnection($dbName);
            if (!$pdo) return 0;
            return (int) $pdo->query("SELECT COUNT(*) FROM manager")->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    private function getLeagueManagerList(string $dbName): array
    {
        try {
            $pdo = $this->openLeagueConnection($dbName);
            if (!$pdo) return [];
            return $pdo->query(
                "SELECT id, manager_name, alias, role, status
                 FROM manager
                 ORDER BY FIELD(role, 'admin', 'maintainer', 'manager'), manager_name ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    private function openLeagueConnection(string $dbName): ?\PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$dbName};charset=utf8",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException) {
            return null;
        }
    }
}
