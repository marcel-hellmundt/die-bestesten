<?php

trait PlayerTrait
{
    public function getPlayersInClub(string $clubId, ?string $seasonId = null): array
    {
        $seasonId = $seasonId ?? $this->getActiveSeasonId();

        $sql = "
            SELECT p.id, p.first_name, p.last_name, p.displayname, p.country_id,
                   pis.position AS season_position
            FROM player_in_club pic
            JOIN player p ON pic.player_id = p.id
            LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = :season_id
            WHERE pic.club_id = :club_id AND pic.to_date IS NULL
            ORDER BY
                FIELD(pis.position, 'GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD', NULL),
                p.last_name ASC, p.first_name ASC
        ";

        $query = $this->con->prepare($sql);
        $query->execute([':club_id' => $clubId, ':season_id' => $seasonId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerList(?string $countryId = null, ?string $seasonId = null): array
    {
        $seasonId = $seasonId ?? $this->getActiveSeasonId();

        $sql = "
            SELECT p.*,
                   COALESCE(SUM(pr.points), 0) AS total_points
            FROM player p
            LEFT JOIN player_rating pr ON p.id = pr.player_id
            LEFT JOIN matchday m       ON pr.matchday_id = m.id AND m.season_id = :season_id
        ";

        $params = [':season_id' => $seasonId];

        if ($countryId) {
            $sql .= " WHERE p.country_id = :country_id";
            $params[':country_id'] = $countryId;
        }

        $sql .= " GROUP BY p.id ORDER BY p.last_name ASC, p.first_name ASC";

        $query = $this->con->prepare($sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlayerDetail(string $id, ?string $seasonId = null): array|false
    {
        $player = $this->getPlayerById($id);
        if (!$player) return false;

        $seasonId = $seasonId ?? $this->getActiveSeasonId();

        // Current club (to_date IS NULL = current contract)
        $q = $this->con->prepare("
            SELECT pc.from_date, pc.on_loan, c.id AS club_id, c.name, c.short_name
            FROM player_in_club pc
            JOIN club c ON pc.club_id = c.id
            WHERE pc.player_id = :id AND pc.to_date IS NULL
            LIMIT 1
        ");
        $q->execute([':id' => $id]);
        $player['current_club'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;

        // Season data and ratings
        if ($seasonId) {
            $q = $this->con->prepare("
                SELECT price, position, photo_uploaded
                FROM player_in_season
                WHERE player_id = :player_id AND season_id = :season_id
                LIMIT 1
            ");
            $q->execute([':player_id' => $id, ':season_id' => $seasonId]);
            $player['current_season'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;

            $q = $this->con->prepare("
                SELECT pr.id, pr.grade, pr.participation,
                       pr.goals, pr.assists, pr.clean_sheet,
                       pr.sds, pr.red_card, pr.yellow_red_card, pr.points,
                       pr.club_id, c.logo_uploaded AS club_logo_uploaded,
                       m.number AS matchday_number, m.kickoff_date
                FROM player_rating pr
                JOIN matchday m ON pr.matchday_id = m.id
                JOIN club c     ON c.id = pr.club_id
                WHERE pr.player_id = :player_id AND m.season_id = :season_id
                ORDER BY m.number ASC
            ");
            $q->execute([':player_id' => $id, ':season_id' => $seasonId]);
            $player['ratings'] = $q->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $player['current_season'] = null;
            $player['ratings']        = [];
        }

        // All seasons (sorted newest first) with aggregated points
        $q = $this->con->prepare("
            SELECT pis.season_id, pis.price, pis.position, pis.photo_uploaded,
                   s.start_date AS season_start,
                   COALESCE(SUM(pr.points), 0) AS total_points
            FROM player_in_season pis
            JOIN season s ON s.id = pis.season_id
            LEFT JOIN matchday m ON m.season_id = pis.season_id
            LEFT JOIN player_rating pr ON pr.player_id = pis.player_id AND pr.matchday_id = m.id
            WHERE pis.player_id = :player_id
            GROUP BY pis.season_id, pis.price, pis.position, pis.photo_uploaded, s.start_date
            ORDER BY s.start_date DESC
        ");
        $q->execute([':player_id' => $id]);
        $player['seasons'] = $q->fetchAll(PDO::FETCH_ASSOC);

        // All club stints (sorted newest first)
        $q = $this->con->prepare("
            SELECT pic.club_id, pic.from_date, pic.to_date, pic.on_loan,
                   c.name AS club_name, c.logo_uploaded
            FROM player_in_club pic
            JOIN club c ON pic.club_id = c.id
            WHERE pic.player_id = :player_id
            ORDER BY pic.from_date DESC
        ");
        $q->execute([':player_id' => $id]);
        $player['clubs'] = $q->fetchAll(PDO::FETCH_ASSOC);

        return $player;
    }

    private function getPlayerById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM player WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function migratePlayer(): array
    {
        // 1. Migrate players
        $playerRows = $this->con_old->query("
            SELECT player_id, country_code, firstname, lastname, displayname,
                   city, date_of_birth, height, weight
            FROM player
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmtPlayer = $this->con->prepare(
            "INSERT INTO player
                (id, country_id, first_name, last_name, displayname, birth_city, date_of_birth, height_cm, weight_kg)
             VALUES
                (:id, :country_id, :first_name, :last_name, :displayname, :birth_city, :date_of_birth, :height_cm, :weight_kg)
             ON DUPLICATE KEY UPDATE
               country_id     = VALUES(country_id),
               first_name     = VALUES(first_name),
               last_name      = VALUES(last_name),
               displayname    = VALUES(displayname),
               birth_city     = VALUES(birth_city),
               date_of_birth  = VALUES(date_of_birth),
               height_cm      = VALUES(height_cm),
               weight_kg      = VALUES(weight_kg)"
        );

        foreach ($playerRows as $row) {
            $stmtPlayer->execute([
                ':id'           => $row['player_id'],
                ':country_id'   => $row['country_code'],
                ':first_name'   => $row['firstname'],
                ':last_name'    => $row['lastname'],
                ':displayname'  => $row['displayname'],
                ':birth_city'   => $row['city'],
                ':date_of_birth'=> $row['date_of_birth'],
                ':height_cm'    => $row['height'],
                ':weight_kg'    => $row['weight'],
            ]);
        }

        // 2. Migrate player_in_season
        // Old schema: player_in_season_id (UUID, reusable), has_photo instead of photo_uploaded
        $allSeasonRows = $this->con_old->query("
            SELECT pis.player_in_season_id, pis.player_id, pis.season_id,
                   pis.price, pis.position, pis.has_photo,
                   p.player_id IS NOT NULL AS player_exists
            FROM player_in_season pis
            LEFT JOIN player p ON p.player_id = pis.player_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmtSeason = $this->con->prepare(
            "INSERT INTO player_in_season (id, player_id, season_id, price, position, photo_uploaded)
             VALUES (:id, :player_id, :season_id, :price, :position, :photo_uploaded)
             ON DUPLICATE KEY UPDATE
               price          = VALUES(price),
               position       = VALUES(position),
               photo_uploaded = VALUES(photo_uploaded)"
        );

        $migratedSeasons = 0;
        $skipped = [];

        foreach ($allSeasonRows as $row) {
            if (!$row['player_exists']) {
                $skipped[] = [
                    'player_in_season_id' => $row['player_in_season_id'],
                    'player_id'           => $row['player_id'],
                    'season_id'           => $row['season_id'],
                ];
                continue;
            }

            $stmtSeason->execute([
                ':id'            => $row['player_in_season_id'],
                ':player_id'     => $row['player_id'],
                ':season_id'     => $row['season_id'],
                ':price'         => $row['price'],
                ':position'      => $row['position'],
                ':photo_uploaded'=> $row['has_photo'],
            ]);
            $migratedSeasons++;
        }

        // 3. Migrate player_in_club
        // Old schema: player_in_club_id (UUID, reusable), is_loan instead of on_loan
        $allClubRows = $this->con_old->query("
            SELECT pic.player_in_club_id, pic.player_id, pic.club_id,
                   pic.from_date, pic.to_date, pic.is_loan,
                   p.player_id IS NOT NULL AS player_exists,
                   c.club_id   IS NOT NULL AS club_exists
            FROM player_in_club pic
            LEFT JOIN player p ON p.player_id = pic.player_id
            LEFT JOIN club   c ON c.club_id   = pic.club_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmtClub = $this->con->prepare(
            "INSERT INTO player_in_club (id, player_id, club_id, from_date, to_date, on_loan)
             VALUES (:id, :player_id, :club_id, :from_date, :to_date, :on_loan)
             ON DUPLICATE KEY UPDATE
               from_date = VALUES(from_date),
               to_date   = VALUES(to_date),
               on_loan   = VALUES(on_loan)"
        );

        $migratedClubs = 0;
        $skippedClubs  = [];

        foreach ($allClubRows as $row) {
            if (!$row['player_exists'] || !$row['club_exists']) {
                $skippedClubs[] = [
                    'player_in_club_id' => $row['player_in_club_id'],
                    'player_id'         => $row['player_id'],
                    'club_id'           => $row['club_id'],
                    'reason'            => !$row['player_exists'] ? 'player not found' : 'club not found',
                ];
                continue;
            }

            $stmtClub->execute([
                ':id'        => $row['player_in_club_id'],
                ':player_id' => $row['player_id'],
                ':club_id'   => $row['club_id'],
                ':from_date' => $row['from_date'],
                ':to_date'   => $row['to_date'],
                ':on_loan'   => $row['is_loan'],
            ]);
            $migratedClubs++;
        }

        // 4. Migrate player_rating
        // Old schema: player_rating_id, season_id + matchday (number) → resolve to matchday_id
        // Ignored columns: ligainsider_grade, is_live
        $allRatingRows = $this->con_old->query("
            SELECT pr.player_rating_id, pr.player_id, pr.club_id, pr.season_id, pr.matchday AS matchday_number,
                   pr.grade, pr.start_lineup, pr.substitution,
                   pr.goals, pr.assists, pr.clean_sheet,
                   pr.sds, pr.red_card, pr.yellow_red_card, pr.points,
                   p.player_id IS NOT NULL AS player_exists
            FROM player_rating pr
            LEFT JOIN player p ON p.player_id = pr.player_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Build (season_id + matchday_number) → matchday_id map from new DB
        $matchdayRows = $this->con->query("SELECT id, season_id, number FROM matchday")->fetchAll(PDO::FETCH_ASSOC);
        $matchdayMap  = [];
        foreach ($matchdayRows as $m) {
            $matchdayMap[$m['season_id'] . '_' . $m['number']] = $m['id'];
        }

        $stmtRating = $this->con->prepare(
            "INSERT INTO player_rating
                (id, player_id, matchday_id, club_id, grade, participation,
                 goals, assists, clean_sheet, sds, red_card, yellow_red_card, points)
             VALUES
                (:id, :player_id, :matchday_id, :club_id, :grade, :participation,
                 :goals, :assists, :clean_sheet, :sds, :red_card, :yellow_red_card, :points)
             ON DUPLICATE KEY UPDATE
               club_id         = VALUES(club_id),
               grade           = VALUES(grade),
               participation   = VALUES(participation),
               goals           = VALUES(goals),
               assists         = VALUES(assists),
               clean_sheet     = VALUES(clean_sheet),
               sds             = VALUES(sds),
               red_card        = VALUES(red_card),
               yellow_red_card = VALUES(yellow_red_card),
               points          = VALUES(points)"
        );

        $migratedRatings       = 0;
        $skippedRatingsByReason = [];

        foreach ($allRatingRows as $row) {
            $matchdayId = $matchdayMap[$row['season_id'] . '_' . $row['matchday_number']] ?? null;

            if (!$row['player_exists'] || !$matchdayId) {
                $reason = !$row['player_exists'] ? 'player not found' : 'matchday not found';
                $skippedRatingsByReason[$reason] = ($skippedRatingsByReason[$reason] ?? 0) + 1;
                continue;
            }

            $stmtRating->execute([
                ':id'            => $row['player_rating_id'],
                ':player_id'     => $row['player_id'],
                ':matchday_id'   => $matchdayId,
                ':club_id'       => $row['club_id'],
                ':grade'         => ($row['grade'] == 0) ? null : $row['grade'],
                ':participation' => $row['start_lineup'] ? 'starting' : ($row['substitution'] ? 'substitute' : null),
                ':goals'         => $row['goals'],
                ':assists'       => $row['assists'],
                ':clean_sheet'   => $row['clean_sheet'],
                ':sds'           => $row['sds'],
                ':red_card'      => $row['red_card'],
                ':yellow_red_card' => $row['yellow_red_card'],
                ':points'        => $row['points'],
            ]);
            $migratedRatings++;
        }

        return [
            'status'            => true,
            'migrated_players'  => count($playerRows),
            'migrated_seasons'  => $migratedSeasons,
            'skipped_seasons'   => $skipped,
            'migrated_clubs'    => $migratedClubs,
            'skipped_clubs'     => $skippedClubs,
            'migrated_ratings'  => $migratedRatings,
            'skipped_ratings'   => array_sum($skippedRatingsByReason),
            'skipped_ratings_by_reason' => $skippedRatingsByReason,
        ];
    }

    private function getActiveSeasonId(): ?string
    {
        $q = $this->con->prepare("SELECT id FROM season ORDER BY start_date DESC LIMIT 1");
        $q->execute();
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row['id'] ?? null;
    }
}
