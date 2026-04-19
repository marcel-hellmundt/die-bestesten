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
               team_name = VALUES(team_name)"
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

        // Build matchday lookup: (season_id, number) → id (new DB)
        $matchdayRows = $this->con->query(
            "SELECT id, season_id, number FROM matchday"
        )->fetchAll(PDO::FETCH_ASSOC);
        $matchdayMap = [];
        foreach ($matchdayRows as $md) {
            $matchdayMap[$md['season_id'] . '_' . $md['number']] = $md['id'];
        }

        // Build season start_date lookup for fallback when old DB has no matchday data
        $seasonRows = $this->con->query(
            "SELECT id, start_date FROM season"
        )->fetchAll(PDO::FETCH_ASSOC);
        $seasonStartDates = [];
        foreach ($seasonRows as $s) {
            $seasonStartDates[$s['id']] = $s['start_date'];
        }

        // Fetch real matchday dates from old DB (separate query to avoid collation issues)
        $oldMdDates = [];
        try {
            $oldMdRows = $this->con_old->query(
                "SELECT season_id, number, start_date, kickoff_date FROM matchday"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($oldMdRows as $md) {
                $oldMdDates[$md['season_id'] . '_' . $md['number']] = $md;
            }
        } catch (\Exception $e) {
            // old DB may not have matchday table — proceed with fallback dates
        }

        // Update existing matchdays in new DB with real data from old DB
        $stmtUpdateMatchday = $this->con->prepare(
            "UPDATE matchday SET start_date = :start_date, kickoff_date = :kickoff_date
             WHERE id = :id"
        );
        foreach ($oldMdDates as $key => $md) {
            if (isset($matchdayMap[$key])) {
                $stmtUpdateMatchday->execute([
                    ':id'           => $matchdayMap[$key],
                    ':start_date'   => $md['start_date'],
                    ':kickoff_date' => $md['kickoff_date'],
                ]);
            }
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
                matchday_id        = VALUES(matchday_id),
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
                $newId      = $this->con->query("SELECT UUID()")->fetchColumn();
                $realMd     = $oldMdDates[$key] ?? null;
                $startDate  = $realMd['start_date']  ?? ($seasonStartDates[$row['season_id']] ?? date('Y-m-d'));
                $kickoff    = $realMd['kickoff_date'] ?? ($startDate . ' 00:00:00');
                $stmtCreateMatchday->execute([
                    ':id'           => $newId,
                    ':season_id'    => $row['season_id'],
                    ':number'       => $row['matchday_number'],
                    ':start_date'   => $startDate,
                    ':kickoff_date' => $kickoff,
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

        // Create transactions (start budget + matchday income)
        $migratedTransactions = 0;

        $checkStartBudget = $conLeague->prepare(
            "SELECT COUNT(*) FROM transaction
             WHERE team_id = :team_id AND matchday_id IS NULL AND reason = 'Startguthaben'"
        );
        $stmtStartBudget = $conLeague->prepare(
            "INSERT INTO transaction (id, team_id, amount, reason, matchday_id)
             VALUES (UUID(), :team_id, 50000000, 'Startguthaben', NULL)"
        );

        $checkMatchdayIncome = $conLeague->prepare(
            "SELECT COUNT(*) FROM transaction
             WHERE team_id = :team_id AND matchday_id = :matchday_id AND reason = 'Spieltagseinnahmen'"
        );
        $stmtMatchdayIncome = $conLeague->prepare(
            "INSERT INTO transaction (id, team_id, amount, reason, matchday_id, created_at)
             VALUES (UUID(), :team_id, :amount, 'Spieltagseinnahmen', :matchday_id, :created_at)"
        );

        // One start-budget per team
        foreach ($rows as $row) {
            $checkStartBudget->execute([':team_id' => $row['team_id']]);
            if ((int) $checkStartBudget->fetchColumn() === 0) {
                $stmtStartBudget->execute([':team_id' => $row['team_id']]);
                $migratedTransactions++;
            }
        }

        // One income transaction per team_rating with points > 0
        foreach ($ratingRows as $row) {
            $points = (int) $row['points'];
            if ($points <= 0) continue;
            $key        = $row['season_id'] . '_' . $row['matchday_number'];
            $matchdayId = $matchdayMap[$key] ?? null;
            if (!$matchdayId) continue;
            $checkMatchdayIncome->execute([':team_id' => $row['team_id'], ':matchday_id' => $matchdayId]);
            if ((int) $checkMatchdayIncome->fetchColumn() === 0) {
                $kickoff   = $oldMdDates[$key]['kickoff_date'] ?? null;
                $createdAt = $kickoff
                    ? date('Y-m-d H:i:s', strtotime($kickoff . ' +3 days'))
                    : date('Y-m-d H:i:s');
                $stmtMatchdayIncome->execute([
                    ':team_id'     => $row['team_id'],
                    ':matchday_id' => $matchdayId,
                    ':amount'      => $points * 20000,
                    ':created_at'  => $createdAt,
                ]);
                $migratedTransactions++;
            }
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

        // Migrate offers
        $migratedOffers = 0;
        $statusMap = [
            'pending'   => 'pending',
            'success'   => 'success',
            'lost'      => 'lost',
            'cancelled' => 'cancelled',
            'rejected'  => 'cancelled',
            'accepted'  => 'success',
        ];
        try {
            $offerRows = $this->con_old->query(
                "SELECT offer_id, player_id, team_id, transferwindow_id,
                        offer_value, price_snapshot, status, offer_date
                 FROM offer"
            )->fetchAll(PDO::FETCH_ASSOC);

            $stmtOffer = $conLeague->prepare(
                "INSERT INTO offer (id, player_id, team_id, transferwindow_id, offer_value, price_snapshot, status, created_at)
                 VALUES (:id, :player_id, :team_id, :transferwindow_id, :offer_value, :price_snapshot, :status, :created_at)
                 ON DUPLICATE KEY UPDATE id = id"
            );

            foreach ($offerRows as $row) {
                $stmtOffer->execute([
                    ':id'                => $row['offer_id'],
                    ':player_id'         => $row['player_id'],
                    ':team_id'           => $row['team_id'],
                    ':transferwindow_id' => $row['transferwindow_id'],
                    ':offer_value'       => $row['offer_value'],
                    ':price_snapshot'    => $row['price_snapshot'],
                    ':status'            => $statusMap[$row['status']] ?? 'cancelled',
                    ':created_at'        => $row['offer_date'],
                ]);
                $migratedOffers++;
            }

            // Create purchase transactions for successful offers
            $successOffers = array_values(array_filter(
                $offerRows,
                fn($r) => ($statusMap[$r['status']] ?? 'cancelled') === 'success'
            ));

            if (!empty($successOffers)) {
                // Bulk-fetch transferwindow data (matchday_id + end_date) from global DB
                $twIds        = array_values(array_unique(array_column($successOffers, 'transferwindow_id')));
                $twMap        = [];
                $placeholders = implode(',', array_fill(0, count($twIds), '?'));
                $twStmt       = $this->con->prepare(
                    "SELECT id, matchday_id, end_date FROM transferwindow WHERE id IN ($placeholders)"
                );
                $twStmt->execute($twIds);
                foreach ($twStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $twMap[$r['id']] = $r;
                }

                // Bulk-fetch player displaynames from global DB
                $playerIds    = array_values(array_unique(array_column($successOffers, 'player_id')));
                $playerMap    = [];
                $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
                $pStmt        = $this->con->prepare(
                    "SELECT id, displayname FROM player WHERE id IN ($placeholders)"
                );
                $pStmt->execute($playerIds);
                foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $playerMap[$r['id']] = $r['displayname'];
                }

                $checkOfferTx = $conLeague->prepare(
                    "SELECT COUNT(*) FROM transaction
                     WHERE team_id = :team_id AND reason = :reason AND matchday_id = :matchday_id"
                );
                $stmtOfferTx = $conLeague->prepare(
                    "INSERT INTO transaction (id, team_id, amount, reason, matchday_id, created_at)
                     VALUES (UUID(), :team_id, :amount, :reason, :matchday_id, :created_at)"
                );

                foreach ($successOffers as $row) {
                    $tw = $twMap[$row['transferwindow_id']] ?? null;
                    if (!$tw || !$tw['matchday_id']) continue;
                    $displayname = $playerMap[$row['player_id']] ?? 'Unbekannt';
                    $reason      = 'Spielerkauf: ' . $displayname;
                    $checkOfferTx->execute([
                        ':team_id'    => $row['team_id'],
                        ':reason'     => $reason,
                        ':matchday_id' => $tw['matchday_id'],
                    ]);
                    if ((int) $checkOfferTx->fetchColumn() === 0) {
                        $stmtOfferTx->execute([
                            ':team_id'     => $row['team_id'],
                            ':amount'      => -(int) $row['offer_value'],
                            ':reason'      => $reason,
                            ':matchday_id' => $tw['matchday_id'],
                            ':created_at'  => $tw['end_date'],
                        ]);
                        $migratedTransactions++;
                    }
                }
            }
        } catch (PDOException) {
            // offer may not exist in older DBs — skip silently
        }

        // Migrate sells
        $migratedSells = 0;
        try {
            $sellRows = $this->con_old->query(
                "SELECT sell_id, player_id, team_id, transferwindow_id, price, sell_date
                 FROM sell"
            )->fetchAll(PDO::FETCH_ASSOC);

            $stmtSell = $conLeague->prepare(
                "INSERT INTO sell (id, player_id, team_id, transferwindow_id, price, created_at)
                 VALUES (:id, :player_id, :team_id, :transferwindow_id, :price, :created_at)
                 ON DUPLICATE KEY UPDATE id = id"
            );

            foreach ($sellRows as $row) {
                $stmtSell->execute([
                    ':id'                => $row['sell_id'],
                    ':player_id'         => $row['player_id'],
                    ':team_id'           => $row['team_id'],
                    ':transferwindow_id' => $row['transferwindow_id'],
                    ':price'             => $row['price'],
                    ':created_at'        => $row['sell_date'],
                ]);
                $migratedSells++;
            }

            // Create sell transactions
            $twIds        = array_values(array_unique(array_column($sellRows, 'transferwindow_id')));
            $twMapSell    = [];
            $placeholders = implode(',', array_fill(0, count($twIds), '?'));
            $twStmt       = $this->con->prepare(
                "SELECT id, matchday_id FROM transferwindow WHERE id IN ($placeholders)"
            );
            $twStmt->execute($twIds);
            foreach ($twStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $twMapSell[$r['id']] = $r;
            }

            $sellPlayerIds = array_values(array_unique(array_column($sellRows, 'player_id')));
            $sellPlayerMap = [];
            $placeholders  = implode(',', array_fill(0, count($sellPlayerIds), '?'));
            $pStmt         = $this->con->prepare(
                "SELECT id, displayname FROM player WHERE id IN ($placeholders)"
            );
            $pStmt->execute($sellPlayerIds);
            foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sellPlayerMap[$r['id']] = $r['displayname'];
            }

            $checkSellTx = $conLeague->prepare(
                "SELECT COUNT(*) FROM transaction
                 WHERE team_id = :team_id AND reason = :reason AND matchday_id = :matchday_id"
            );
            $stmtSellTx = $conLeague->prepare(
                "INSERT INTO transaction (id, team_id, amount, reason, matchday_id, created_at)
                 VALUES (UUID(), :team_id, :amount, :reason, :matchday_id, :created_at)"
            );

            foreach ($sellRows as $row) {
                $tw = $twMapSell[$row['transferwindow_id']] ?? null;
                if (!$tw || !$tw['matchday_id']) continue;
                $displayname = $sellPlayerMap[$row['player_id']] ?? 'Unbekannt';
                $reason      = 'Spielerverkauf: ' . $displayname;
                $checkSellTx->execute([
                    ':team_id'     => $row['team_id'],
                    ':reason'      => $reason,
                    ':matchday_id' => $tw['matchday_id'],
                ]);
                if ((int) $checkSellTx->fetchColumn() === 0) {
                    $stmtSellTx->execute([
                        ':team_id'     => $row['team_id'],
                        ':amount'      => (int) $row['price'],
                        ':reason'      => $reason,
                        ':matchday_id' => $tw['matchday_id'],
                        ':created_at'  => $row['sell_date'],
                    ]);
                    $migratedTransactions++;
                }
            }
        } catch (PDOException) {
            // sell may not exist in older DBs — skip silently
        }

        // Migrate player_in_team
        // Ensure offer_id / sell_id / sell-table exist (added after initial schema creation)
        try { $conLeague->exec("ALTER TABLE player_in_team ADD COLUMN offer_id CHAR(36) NULL DEFAULT NULL"); } catch (PDOException) {}
        try { $conLeague->exec("ALTER TABLE player_in_team ADD COLUMN sell_id  CHAR(36) NULL DEFAULT NULL"); } catch (PDOException) {}
        try { $conLeague->exec("ALTER TABLE player_in_team ADD FOREIGN KEY (offer_id) REFERENCES offer(id)"); } catch (PDOException) {}
        try { $conLeague->exec("ALTER TABLE player_in_team ADD FOREIGN KEY (sell_id)  REFERENCES sell(id)");  } catch (PDOException) {}

        $migratedPlayerInTeam = 0;
        $skippedPlayerInTeam  = 0;
        try {
            $pitRows = $this->con_old->query(
                "SELECT pit.player_in_team_id, pit.team_id, pit.player_id,
                        pit.first_matchday, pit.last_matchday, pit.offer_id, pit.sell_id,
                        t.season_id
                 FROM player_in_team pit
                 JOIN team t ON t.team_id = pit.team_id"
            )->fetchAll(PDO::FETCH_ASSOC);

            $stmtPit = $conLeague->prepare(
                "INSERT INTO player_in_team (id, team_id, player_id, from_matchday_id, to_matchday_id, offer_id, sell_id)
                 VALUES (:id, :team_id, :player_id, :from_matchday_id, :to_matchday_id, :offer_id, :sell_id)
                 ON DUPLICATE KEY UPDATE id = id"
            );

            foreach ($pitRows as $row) {
                $fromKey        = $row['season_id'] . '_' . $row['first_matchday'];
                $fromMatchdayId = $matchdayMap[$fromKey] ?? null;
                if (!$fromMatchdayId) {
                    $skippedPlayerInTeam++;
                    continue;
                }

                $toMatchdayId = null;
                if ($row['last_matchday'] !== null) {
                    $toKey        = $row['season_id'] . '_' . $row['last_matchday'];
                    $toMatchdayId = $matchdayMap[$toKey] ?? null;
                }

                $stmtPit->execute([
                    ':id'               => $row['player_in_team_id'],
                    ':team_id'          => $row['team_id'],
                    ':player_id'        => $row['player_id'],
                    ':from_matchday_id' => $fromMatchdayId,
                    ':to_matchday_id'   => $toMatchdayId,
                    ':offer_id'         => $row['offer_id'] ?: null,
                    ':sell_id'          => $row['sell_id'] ?: null,
                ]);
                $migratedPlayerInTeam++;
            }
        } catch (PDOException) {
            // player_in_team may not exist in older DBs — skip silently
        }

        // Migrate team_lineup
        $migratedLineup = 0;
        $skippedLineup  = 0;
        try {
            $lineupRows = $this->con_old->query(
                "SELECT tl.team_lineup_id, tl.team_id, tl.player_id,
                        tl.matchday, tl.nominated, tl.position_index,
                        t.season_id
                 FROM team_lineup tl
                 JOIN team t ON t.team_id = tl.team_id"
            )->fetchAll(PDO::FETCH_ASSOC);

            $stmtLineup = $conLeague->prepare(
                "INSERT INTO team_lineup (id, team_id, player_id, matchday_id, nominated, position_index)
                 VALUES (:id, :team_id, :player_id, :matchday_id, :nominated, :position_index)
                 ON DUPLICATE KEY UPDATE id = id"
            );

            foreach ($lineupRows as $row) {
                $key        = $row['season_id'] . '_' . $row['matchday'];
                $matchdayId = $matchdayMap[$key] ?? null;
                if (!$matchdayId) {
                    $skippedLineup++;
                    continue;
                }

                $stmtLineup->execute([
                    ':id'             => $row['team_lineup_id'],
                    ':team_id'        => $row['team_id'],
                    ':player_id'      => $row['player_id'],
                    ':matchday_id'    => $matchdayId,
                    ':nominated'      => $row['nominated'],
                    ':position_index' => $row['position_index'],
                ]);
                $migratedLineup++;
            }
        } catch (PDOException) {
            // team_lineup may not exist in older DBs — skip silently
        }

        return [
            'status'            => true,
            'teams'             => ['migrated' => $migrated,             'skipped' => $skipped],
            'team_ratings'      => ['migrated' => $migratedRatings],
            'transactions'      => ['migrated' => $migratedTransactions],
            'team_awards'       => ['migrated' => $migratedAwards],
            'offers'            => ['migrated' => $migratedOffers],
            'sells'             => ['migrated' => $migratedSells],
            'player_in_team'    => ['migrated' => $migratedPlayerInTeam, 'skipped' => $skippedPlayerInTeam],
            'team_lineup'       => ['migrated' => $migratedLineup,       'skipped' => $skippedLineup],
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
            $rows = $pdo->query(
                "SELECT m.id, m.manager_name, m.alias, m.status,
                        GROUP_CONCAT(mr.role ORDER BY mr.role SEPARATOR ',') AS roles_csv
                 FROM manager m
                 LEFT JOIN manager_role mr ON mr.manager_id = m.id
                 GROUP BY m.id
                 ORDER BY
                     CASE WHEN MAX(mr.role = 'admin')      = 1 THEN 0
                          WHEN MAX(mr.role = 'maintainer') = 1 THEN 1
                          ELSE 2 END ASC,
                     m.manager_name ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['roles'] = $row['roles_csv'] ? explode(',', $row['roles_csv']) : [];
                unset($row['roles_csv']);
            }
            return $rows;
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
