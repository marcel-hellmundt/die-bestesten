<?php

trait AwardTrait
{
    public function getAwardWinners(): array
    {
        // Awards from global DB, sorted by importance
        $awards = $this->con->query(
            "SELECT id, name, icon, sort_index FROM award ORDER BY sort_index ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($awards)) return [];

        // All seasons from global DB, oldest first
        $seasons = $this->con->query(
            "SELECT id, start_date FROM season ORDER BY start_date DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // All winners from league DB
        $winners = $this->con_league->query(
            "SELECT ta.award_id, t.season_id, t.id AS team_id, t.team_name, t.color,
                    m.id AS manager_id, m.manager_name, m.alias
             FROM team_award ta
             JOIN team t ON t.id = ta.team_id
             JOIN manager m ON m.id = t.manager_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Compute stats for all winner teams in one query
        $teamIds = array_unique(array_column($winners, 'team_id'));
        $statsMap = [];
        if (!empty($teamIds)) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $statsRows = $this->con_league->prepare(
                "SELECT team_id,
                        SUM(points)                  AS total_points,
                        SUM(max_points - points)     AS total_gap,
                        MIN(points)                  AS min_matchday_points
                 FROM team_rating
                 WHERE team_id IN ($placeholders) AND invalid = 0
                 GROUP BY team_id"
            );
            $statsRows->execute($teamIds);
            foreach ($statsRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $statsMap[$row['team_id']] = [
                    'total_points'         => (int) $row['total_points'],
                    'total_gap'            => (int) $row['total_gap'],
                    'min_matchday_points'  => (int) $row['min_matchday_points'],
                ];
            }
        }

        // Index winners by award_id + season_id, attach stats
        $winnerMap = [];
        foreach ($winners as $w) {
            $stats = $statsMap[$w['team_id']] ?? [];
            $winnerMap[$w['award_id'] . '_' . $w['season_id']] = array_merge($w, $stats);
        }

        // Build result
        $result = [];
        foreach ($awards as $award) {
            $seasonRows = [];
            foreach ($seasons as $season) {
                $key    = $award['id'] . '_' . $season['id'];
                $winner = $winnerMap[$key] ?? null;
                $seasonRows[] = [
                    'season_id'   => $season['id'],
                    'start_date'  => $season['start_date'],
                    'winner'      => $winner,
                ];
            }
            $result[] = [
                'id'         => $award['id'],
                'name'       => $award['name'],
                'icon'       => $award['icon'],
                'sort_index' => (int) $award['sort_index'],
                'seasons'    => $seasonRows,
            ];
        }

        return $result;
    }
}
