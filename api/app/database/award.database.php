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
            "SELECT id, start_date FROM season ORDER BY start_date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // All winners from league DB
        $winners = $this->con_league->query(
            "SELECT ta.award_id, t.season_id, t.id AS team_id, t.team_name, t.color,
                    m.id AS manager_id, m.manager_name, m.alias
             FROM team_award ta
             JOIN team t ON t.id = ta.team_id
             JOIN manager m ON m.id = t.manager_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Index winners by award_id + season_id
        $winnerMap = [];
        foreach ($winners as $w) {
            $winnerMap[$w['award_id'] . '_' . $w['season_id']] = $w;
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
