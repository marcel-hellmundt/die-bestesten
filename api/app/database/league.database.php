<?php

trait LeagueTrait
{
    public function getMyLeague(): array|false
    {
        $q = $this->con->prepare(
            "SELECT l.id, l.slug, l.name, l.db_name, l.division_id
             FROM league l
             WHERE l.db_name = :db_name LIMIT 1"
        );
        $q->execute([':db_name' => $_ENV['DB_NAME_LEAGUE']]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function updateLeagueDivision(string $id, ?string $divisionId): void
    {
        $q = $this->con->prepare("UPDATE league SET division_id = :division_id WHERE id = :id");
        $q->execute([':division_id' => $divisionId, ':id' => $id]);
    }

    public function updateLeagueVisibility(string $id, string $visibility): void
    {
        $q = $this->con->prepare("UPDATE league SET visibility = :visibility WHERE id = :id");
        $q->execute([':visibility' => $visibility, ':id' => $id]);
    }

    public function getLeagueList(): array
    {
        $query = $this->con->prepare("SELECT * FROM league ORDER BY name ASC");
        $query->execute();
        $leagues = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leagues as &$league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['id']);
        }

        return $leagues;
    }

    public function getLeagueById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM league WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        $league = $query->fetch(PDO::FETCH_ASSOC);
        if ($league) {
            $league['manager_count'] = $this->getLeagueManagerCount($id);
            $league['teams']         = $this->getLeagueTeamList($league['db_name']);
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

        // Build season start_date lookup (used for team created_at + matchday fallback dates)
        $seasonRows = $this->con->query(
            "SELECT id, start_date FROM season"
        )->fetchAll(PDO::FETCH_ASSOC);
        $seasonStartDates = [];
        foreach ($seasonRows as $s) {
            $seasonStartDates[$s['id']] = $s['start_date'];
        }

        $stmt = $conLeague->prepare(
            "INSERT INTO team (id, manager_id, season_id, team_name, color_primary, created_at)
             VALUES (:id, :manager_id, :season_id, :team_name, :color_primary, :created_at)
             ON DUPLICATE KEY UPDATE
               team_name    = VALUES(team_name),
               color_primary = VALUES(color_primary),
               created_at   = VALUES(created_at)"
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
                ':id'            => $row['team_id'],
                ':manager_id'    => $row['manager_id'],
                ':season_id'     => $row['season_id'],
                ':team_name'     => $row['team_name'],
                ':color_primary' => $color,
                ':created_at'    => $seasonStartDates[$row['season_id']] ?? date('Y-m-d'),
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

        // Build red-card map: [team_id][global_matchday_uuid] => [rc, yrc]
        // Use matchday number + season_id to resolve the global UUID via $matchdayMap,
        // so the key matches exactly what the team_rating insert loop uses.
        $rcLineupRows = $this->con_old->query(
            "SELECT tl.team_id, tl.player_id, tl.matchday AS matchday_number, t.season_id
             FROM team_lineup tl
             JOIN team t ON t.team_id = tl.team_id
             WHERE tl.nominated = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $rcMap = [];
        if (!empty($rcLineupRows)) {
            $lineupPlayerIds = array_values(array_unique(array_column($rcLineupRows, 'player_id')));
            $plh = implode(',', array_fill(0, count($lineupPlayerIds), '?'));
            $prq = $this->con->prepare(
                "SELECT player_id, matchday_id, red_card, yellow_red_card
                 FROM player_rating WHERE player_id IN ($plh)
                   AND (red_card = 1 OR yellow_red_card = 1)"
            );
            $prq->execute($lineupPlayerIds);
            $rcByPlayer = [];
            foreach ($prq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rcByPlayer[$r['player_id']][$r['matchday_id']] = [(int)$r['red_card'], (int)$r['yellow_red_card']];
            }
            foreach ($rcLineupRows as $l) {
                $globalMatchdayId = $matchdayMap[$l['season_id'] . '_' . $l['matchday_number']] ?? null;
                if (!$globalMatchdayId) continue;
                [$rc, $yrc] = $rcByPlayer[$l['player_id']][$globalMatchdayId] ?? [0, 0];
                $rcMap[$l['team_id']][$globalMatchdayId][0] = ($rcMap[$l['team_id']][$globalMatchdayId][0] ?? 0) + $rc;
                $rcMap[$l['team_id']][$globalMatchdayId][1] = ($rcMap[$l['team_id']][$globalMatchdayId][1] ?? 0) + $yrc;
            }
        }

        $stmtRating = $conLeague->prepare(
            "INSERT INTO team_rating (
                id, team_id, matchday_id, points, max_points, goals, assists,
                red_cards, yellow_red_cards,
                clean_sheet, sds, sds_defender, missed_goals,
                points_goalkeeper, points_defender, points_midfielder, points_forward, invalid
             ) VALUES (
                :id, :team_id, :matchday_id, :points, :max_points, :goals, :assists,
                :red_cards, :yellow_red_cards,
                :clean_sheet, :sds, :sds_defender, :missed_goals,
                :points_goalkeeper, :points_defender, :points_midfielder, :points_forward, :invalid
             ) ON DUPLICATE KEY UPDATE
                matchday_id        = VALUES(matchday_id),
                points             = VALUES(points),
                max_points         = VALUES(max_points),
                goals              = VALUES(goals),
                assists            = VALUES(assists),
                red_cards          = VALUES(red_cards),
                yellow_red_cards   = VALUES(yellow_red_cards),
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
                ':red_cards'        => $rcMap[$row['team_id']][$matchdayId][0] ?? 0,
                ':yellow_red_cards' => $rcMap[$row['team_id']][$matchdayId][1] ?? 0,
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
            "INSERT INTO transaction (id, team_id, amount, reason, matchday_id, created_at)
             VALUES (UUID(), :team_id, 50000000, 'Startguthaben', NULL, :created_at)"
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
                $stmtStartBudget->execute([
                    ':team_id'    => $row['team_id'],
                    ':created_at' => $seasonStartDates[$row['season_id']] ?? date('Y-m-d'),
                ]);
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
        $patchedOffers  = 0;
        $skippedOffers  = 0;
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
                 ON DUPLICATE KEY UPDATE
                     offer_value    = VALUES(offer_value),
                     price_snapshot = VALUES(price_snapshot),
                     status         = VALUES(status),
                     created_at     = VALUES(created_at)"
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
                // MySQL rowCount: 1 = inserted, 2 = updated (values changed), 0 = duplicate but unchanged
                $affected = $stmtOffer->rowCount();
                if ($affected === 1)      { $migratedOffers++; }
                elseif ($affected === 2)  { $patchedOffers++; }
                else                      { $skippedOffers++; }
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
        // Ensure UNIQUE constraint includes team_id so two teams can hold the same player on the same matchday
        try { $conLeague->exec("ALTER TABLE player_in_team DROP INDEX uk_player_from"); } catch (PDOException) {}
        try { $conLeague->exec("ALTER TABLE player_in_team ADD UNIQUE KEY uk_player_from (player_id, team_id, from_matchday_id)"); } catch (PDOException) {}

        $migratedPlayerInTeam = 0;
        $patchedPlayerInTeam  = 0;
        $skippedPlayerInTeam  = 0;
        try {
            $pitRows = $this->con_old->query(
                "SELECT pit.player_in_team_id, pit.team_id, pit.player_id,
                        pit.first_matchday, pit.last_matchday, pit.offer_id, pit.sell_id,
                        t.season_id
                 FROM player_in_team pit
                 JOIN team t ON t.team_id = pit.team_id"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Build sell team_id lookup to guard against cross-team sell_id references
            $sellTeamMap = [];
            try {
                foreach ($this->con_old->query("SELECT sell_id, team_id FROM sell")->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $sellTeamMap[$s['sell_id']] = $s['team_id'];
                }
            } catch (PDOException) {}

            $stmtPit = $conLeague->prepare(
                "INSERT INTO player_in_team (id, team_id, player_id, from_matchday_id, to_matchday_id, offer_id, sell_id)
                 VALUES (:id, :team_id, :player_id, :from_matchday_id, :to_matchday_id, :offer_id, :sell_id)
                 ON DUPLICATE KEY UPDATE
                     from_matchday_id = VALUES(from_matchday_id),
                     to_matchday_id   = VALUES(to_matchday_id),
                     offer_id         = VALUES(offer_id),
                     sell_id          = VALUES(sell_id)"
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
                    ':sell_id'          => ($row['sell_id'] && ($sellTeamMap[$row['sell_id']] ?? null) === $row['team_id'])
                                            ? $row['sell_id'] : null,
                ]);
                $affected = $stmtPit->rowCount();
                if ($affected === 1)     { $migratedPlayerInTeam++; }
                elseif ($affected === 2) { $patchedPlayerInTeam++; }
                else                     { $skippedPlayerInTeam++; }
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
            'offers'            => ['migrated' => $migratedOffers, 'patched' => $patchedOffers, 'skipped' => $skippedOffers],
            'sells'             => ['migrated' => $migratedSells],
            'player_in_team'    => ['migrated' => $migratedPlayerInTeam, 'patched' => $patchedPlayerInTeam, 'skipped' => $skippedPlayerInTeam],
            'team_lineup'       => ['migrated' => $migratedLineup,       'skipped' => $skippedLineup],
            'matchdays_created' => array_values($createdMatchdays),
        ];
    }

    public function validateLeagueRatings(string $leagueId): array
    {
        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $trRows = $con->query(
            "SELECT tr.id, tr.team_id, tr.matchday_id,
                    tr.points, tr.goals, tr.assists, tr.clean_sheet,
                    tr.sds, tr.sds_defender, tr.red_cards, tr.yellow_red_cards,
                    tr.points_goalkeeper, tr.points_defender, tr.points_midfielder, tr.points_forward,
                    tr.invalid,
                    t.team_name, t.season_id AS team_season_id,
                    m.manager_name
             FROM team_rating tr
             JOIN team t ON t.id = tr.team_id
             JOIN manager m ON m.id = t.manager_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($trRows)) {
            return ['status' => true, 'checked' => 0, 'mismatches' => []];
        }

        $allMatchdayIds = array_values(array_unique(array_column($trRows, 'matchday_id')));
        $allTeamIds     = array_values(array_unique(array_column($trRows, 'team_id')));

        $ph  = implode(',', array_fill(0, count($allMatchdayIds), '?'));
        $mdQ = $this->con->prepare(
            "SELECT md.id, md.number, md.season_id FROM matchday md
             JOIN season s ON s.id = md.season_id
             WHERE md.id IN ($ph) AND s.start_date >= '2020-07-01'"
        );
        $mdQ->execute($allMatchdayIds);
        $matchdayMap = [];
        foreach ($mdQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $matchdayMap[$r['id']] = $r;
        }

        $phT = implode(',', array_fill(0, count($allTeamIds), '?'));
        $phM = implode(',', array_fill(0, count($allMatchdayIds), '?'));
        $luQ = $con->prepare(
            "SELECT team_id, matchday_id, player_id FROM team_lineup
             WHERE nominated = 1 AND team_id IN ($phT) AND matchday_id IN ($phM)"
        );
        $luQ->execute(array_merge($allTeamIds, $allMatchdayIds));
        $lineupMap = [];
        foreach ($luQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lineupMap[$r['team_id']][$r['matchday_id']][] = $r['player_id'];
        }

        $allPlayerIds = [];
        foreach ($lineupMap as $byMd) {
            foreach ($byMd as $pids) {
                foreach ($pids as $pid) $allPlayerIds[] = $pid;
            }
        }
        $allPlayerIds = array_values(array_unique($allPlayerIds));

        $prMap  = [];
        $posMap = [];
        if (!empty($allPlayerIds)) {
            $phP  = implode(',', array_fill(0, count($allPlayerIds), '?'));
            $phM2 = implode(',', array_fill(0, count($allMatchdayIds), '?'));
            $prQ  = $this->con->prepare(
                "SELECT player_id, matchday_id,
                        COALESCE(points, 0) AS points, COALESCE(goals, 0) AS goals,
                        COALESCE(assists, 0) AS assists, COALESCE(clean_sheet, 0) AS clean_sheet,
                        COALESCE(sds, 0) AS sds,
                        COALESCE(red_card, 0) AS red_card,
                        COALESCE(yellow_red_card, 0) AS yellow_red_card
                 FROM player_rating WHERE player_id IN ($phP) AND matchday_id IN ($phM2)"
            );
            $prQ->execute(array_merge($allPlayerIds, $allMatchdayIds));
            foreach ($prQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $prMap[$r['player_id']][$r['matchday_id']] = $r;
            }

            $allSeasonIds = array_values(array_unique(array_column($matchdayMap, 'season_id')));
            if (!empty($allSeasonIds)) {
                $phS  = implode(',', array_fill(0, count($allSeasonIds), '?'));
                $pisQ = $this->con->prepare(
                    "SELECT player_id, season_id, position FROM player_in_season
                     WHERE player_id IN ($phP) AND season_id IN ($phS)"
                );
                $pisQ->execute(array_merge($allPlayerIds, $allSeasonIds));
                foreach ($pisQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $posMap[$r['player_id']][$r['season_id']] = $r['position'];
                }
            }
        }

        $mismatches = [];
        foreach ($trRows as $tr) {
            $md = $matchdayMap[$tr['matchday_id']] ?? null;
            if (!$md) continue; // pre-2020/21 or unknown matchday
            $seasonId = $md['season_id'];
            $players  = $lineupMap[$tr['team_id']][$tr['matchday_id']] ?? [];

            $calcInvalid = empty($players) ? 1 : 0;
            $calcPoints = $calcGoals = $calcAssists = $calcClean = 0;
            $calcSds = $calcSdsDef = $calcRc = $calcYrc = 0;
            $calcGk = $calcDef = $calcMid = $calcFwd = 0;

            foreach ($players as $pid) {
                $pr  = $prMap[$pid][$tr['matchday_id']] ?? null;
                if (!$pr) continue;
                $pos = $posMap[$pid][$seasonId] ?? null;
                $calcPoints += (int) $pr['points'];
                $calcGoals  += (int) $pr['goals'];
                $calcAssists += (int) $pr['assists'];
                $calcClean  += (int) $pr['clean_sheet'];
                $calcSds    += (int) $pr['sds'];
                $calcRc     += (int) $pr['red_card'];
                $calcYrc    += (int) $pr['yellow_red_card'];
                if ($pr['sds'] && in_array($pos, ['GOALKEEPER', 'DEFENDER'])) $calcSdsDef++;
                match ($pos) {
                    'GOALKEEPER' => $calcGk  += (int) $pr['points'],
                    'DEFENDER'   => $calcDef += (int) $pr['points'],
                    'MIDFIELDER' => $calcMid += (int) $pr['points'],
                    'FORWARD'    => $calcFwd += (int) $pr['points'],
                    default      => null,
                };
            }

            $checks = [
                'points'            => [(int) $tr['points'],            $calcPoints],
                'goals'             => [(int) $tr['goals'],             $calcGoals],
                'assists'           => [(int) $tr['assists'],           $calcAssists],
                'clean_sheet'       => [(int) $tr['clean_sheet'],       $calcClean],
                'sds'               => [(int) $tr['sds'],               $calcSds],
                'sds_defender'      => [(int) $tr['sds_defender'],      $calcSdsDef],
                'red_cards'         => [(int) $tr['red_cards'],         $calcRc],
                'yellow_red_cards'  => [(int) $tr['yellow_red_cards'],  $calcYrc],
                'points_goalkeeper' => [(int) $tr['points_goalkeeper'], $calcGk],
                'points_defender'   => [(int) $tr['points_defender'],   $calcDef],
                'points_midfielder' => [(int) $tr['points_midfielder'], $calcMid],
                'points_forward'    => [(int) $tr['points_forward'],    $calcFwd],
            ];

            $diff = [];
            foreach ($checks as $field => [$stored, $calculated]) {
                if ($stored !== $calculated) {
                    $diff[$field] = ['stored' => $stored, 'calculated' => $calculated];
                }
            }
            if (!empty($diff)) {
                $mismatches[] = [
                    'team_id'         => $tr['team_id'],
                    'matchday_id'     => $tr['matchday_id'],
                    'team_name'       => $tr['team_name'],
                    'manager_name'    => $tr['manager_name'],
                    'matchday_number' => (int) $md['number'],
                    'season_id'       => $seasonId,
                    'fields'          => $diff,
                ];
            }
        }

        usort($mismatches, fn($a, $b) => ($a['matchday_number'] ?? 0) <=> ($b['matchday_number'] ?? 0));

        return ['status' => true, 'checked' => count($trRows), 'mismatches' => $mismatches];
    }

    public function fixTeamRatingField(string $leagueId, string $teamId, string $matchdayId, string $field, int $value): array
    {
        $allowedNew = [
            'points', 'goals', 'assists', 'clean_sheet', 'sds', 'sds_defender',
            'red_cards', 'yellow_red_cards',
            'points_goalkeeper', 'points_defender', 'points_midfielder', 'points_forward',
        ];
        $allowedOld = [
            'points', 'goals', 'assists', 'clean_sheet', 'sds', 'sds_defender',
            'points_goalkeeper', 'points_defender', 'points_midfielder', 'points_forward',
        ];
        if (!in_array($field, $allowedNew, true)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'Ungültiges Feld'];
        }

        $lq = $this->con->prepare("SELECT db_name FROM league WHERE id = :id LIMIT 1");
        $lq->execute([':id' => $leagueId]);
        $league = $lq->fetch(\PDO::FETCH_ASSOC);
        if (!$league) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Liga nicht gefunden'];
        }

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) {
            http_response_code(500);
            return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];
        }

        $con->prepare("UPDATE team_rating SET $field = ? WHERE team_id = ? AND matchday_id = ?")
            ->execute([$value, $teamId, $matchdayId]);

        if (in_array($field, $allowedOld, true)) {
            $mdNum = $this->con->prepare("SELECT number FROM matchday WHERE id = ? LIMIT 1");
            $mdNum->execute([$matchdayId]);
            $num = $mdNum->fetchColumn();
            if ($num !== false) {
                try {
                    $this->con_old->prepare("UPDATE team_rating SET $field = ? WHERE team_id = ? AND matchday_number = ?")
                        ->execute([$value, $teamId, (int) $num]);
                } catch (\Throwable $e) {
                    error_log('fixTeamRatingField old-DB sync failed: ' . $e->getMessage());
                }
            }
        }

        return ['status' => true];
    }

    public function sendJoinRequestAdminEmail(string $managerName, string $leagueName): void
    {
        try {
            $adminEmails = $this->con->query(
                "SELECT m.email FROM manager m
                 JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (empty($adminEmails)) return;

            $subject = "Beitrittsanfrage: $managerName — die bestesten";
            $body    = "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"UTF-8\"></head>"
                . "<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:600px;margin:0 auto;\">"
                . "<h2 style=\"margin:0 0 12px;\">Neue Beitrittsanfrage</h2>"
                . "<p><strong>" . htmlspecialchars($managerName) . "</strong> möchte der Liga "
                . "<strong>" . htmlspecialchars($leagueName) . "</strong> beitreten.</p>"
                . "<p style=\"color:#64748b;\">Bitte genehmige oder lehne die Anfrage in der Manager-Übersicht ab.</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendJoinRequestAdminEmail failed: ' . $e->getMessage());
        }
    }

    public function sendInviteAcceptedAdminEmail(string $managerName, string $leagueName): void
    {
        try {
            $adminEmails = $this->con->query(
                "SELECT m.email FROM manager m
                 JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (empty($adminEmails)) return;

            $subject = "Einladung angenommen: $managerName — die bestesten";
            $body    = "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"UTF-8\"></head>"
                . "<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:600px;margin:0 auto;\">"
                . "<h2 style=\"margin:0 0 12px;\">Einladung angenommen</h2>"
                . "<p><strong>" . htmlspecialchars($managerName) . "</strong> hat die Einladung zur Liga "
                . "<strong>" . htmlspecialchars($leagueName) . "</strong> angenommen.</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendInviteAcceptedAdminEmail failed: ' . $e->getMessage());
        }
    }

    private function getLeagueManagerCount(string $leagueId): int
    {
        $q = $this->con->prepare("SELECT COUNT(*) FROM manager_league WHERE league_id = ?");
        $q->execute([$leagueId]);
        return (int) $q->fetchColumn();
    }

    private function getLeagueTeamList(string $dbName): array
    {
        try {
            $pdo = $this->openLeagueConnection($dbName);
            if (!$pdo) return [];
            $rows = $pdo->query(
                "SELECT t.id, t.team_name, t.color_primary AS color, t.season_id, t.manager_id,
                        m.manager_name,
                        COALESCE(SUM(tr.points), 0) AS total_points
                 FROM team t
                 JOIN manager m ON m.id = t.manager_id
                 LEFT JOIN team_rating tr ON tr.team_id = t.id
                 GROUP BY t.id, t.team_name, t.color_primary, t.season_id, t.manager_id, m.manager_name
                 ORDER BY t.season_id DESC, t.team_name ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['total_points'] = (int) $row['total_points'];
                $row['color']        = $this->resolveColor($row['color'] ?? null);
            }
            return $rows;
        } catch (\PDOException) {
            return [];
        }
    }

    public function concludeSeasonForLeague(string $leagueId, string $seasonId): array
    {
        $league = $this->getLeagueById($leagueId);
        if (!$league) return ['status' => false, 'message' => 'Liga nicht gefunden'];

        $con = $this->openLeagueConnection($league['db_name']);
        if (!$con) return ['status' => false, 'message' => 'DB-Verbindung fehlgeschlagen'];

        $awardIds = [
            '93e28cd3-07db-11f0-9187-c81f66ca5914', // Meister
            '9f21fdf6-07db-11f0-9187-c81f66ca5914', // Goldene Bürste
            '93e2a7ab-07db-11f0-9187-c81f66ca5914', // Hölzerne Bank
        ];
        $ph = implode(',', array_fill(0, count($awardIds), '?'));

        // Idempotency check
        $existQ = $con->prepare(
            "SELECT COUNT(*) FROM team_award ta JOIN team t ON t.id = ta.team_id
             WHERE t.season_id = ? AND ta.award_id IN ($ph)"
        );
        $existQ->execute([$seasonId, ...$awardIds]);
        if ((int) $existQ->fetchColumn() > 0) {
            return ['status' => true, 'skipped' => true, 'message' => 'Awards bereits vergeben'];
        }

        // Award names from global DB
        $namesQ = $this->con->prepare("SELECT id, name FROM award WHERE id IN ($ph)");
        $namesQ->execute($awardIds);
        $awardNames = array_column($namesQ->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');

        // Meister: highest total points
        $meisterQ = $con->prepare("
            SELECT tr.team_id, t.manager_id, t.team_name
            FROM team_rating tr JOIN team t ON t.id = tr.team_id
            WHERE t.season_id = ? AND tr.invalid = 0
            GROUP BY tr.team_id, t.manager_id, t.team_name
            ORDER BY SUM(tr.points) DESC LIMIT 1
        ");
        $meisterQ->execute([$seasonId]);
        $meister = $meisterQ->fetch(PDO::FETCH_ASSOC);

        // Goldene Bürste: lowest single-matchday points
        $buersteQ = $con->prepare("
            SELECT tr.team_id, t.manager_id, t.team_name
            FROM team_rating tr JOIN team t ON t.id = tr.team_id
            WHERE t.season_id = ? AND tr.invalid = 0
            ORDER BY tr.points ASC LIMIT 1
        ");
        $buersteQ->execute([$seasonId]);
        $goldene = $buersteQ->fetch(PDO::FETCH_ASSOC);

        // Hölzerne Bank: highest total (max_points - points)
        $bankQ = $con->prepare("
            SELECT tr.team_id, t.manager_id, t.team_name
            FROM team_rating tr JOIN team t ON t.id = tr.team_id
            WHERE t.season_id = ? AND tr.invalid = 0
            GROUP BY tr.team_id, t.manager_id, t.team_name
            ORDER BY SUM(tr.max_points - tr.points) DESC LIMIT 1
        ");
        $bankQ->execute([$seasonId]);
        $bank = $bankQ->fetch(PDO::FETCH_ASSOC);

        $toGrant = [
            '93e28cd3-07db-11f0-9187-c81f66ca5914' => $meister,
            '9f21fdf6-07db-11f0-9187-c81f66ca5914' => $goldene,
            '93e2a7ab-07db-11f0-9187-c81f66ca5914' => $bank,
        ];

        $insertAward = $con->prepare(
            "INSERT IGNORE INTO team_award (id, team_id, award_id) VALUES (UUID(), ?, ?)"
        );

        $granted = [];
        foreach ($toGrant as $awardId => $team) {
            if (!$team) continue;
            $insertAward->execute([$team['team_id'], $awardId]);
            $awardName = $awardNames[$awardId] ?? $awardId;
            $this->createNotification(
                $team['manager_id'],
                "Saisonauszeichnung: $awardName",
                null,
                null
            );
            $granted[] = ['award' => $awardName, 'team' => $team['team_name']];
        }

        return ['status' => true, 'skipped' => false, 'granted' => $granted];
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
