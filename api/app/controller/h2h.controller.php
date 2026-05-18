<?php

class H2HController extends _BaseController
{
    public static array $methodRoles = [
        'GET'    => 'manager',
        'POST'   => 'admin',
        'PATCH'  => 'admin',
        'DELETE' => 'admin',
    ];

    protected function get(): mixed
    {
        if ($this->id) {
            $detail = $this->db->getH2HMatchDetail($this->id);
            if (!$detail) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Match not found'];
            }
            return $detail;
        }

        $seasonId = $this->params['season_id'] ?? null;
        if (!$seasonId) {
            $season   = $this->db->getActiveSeason();
            $seasonId = $season ? $season['id'] : null;
        }
        if (!$seasonId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id required'];
        }
        return $this->db->getH2HOverview($seasonId);
    }

    protected function post(): mixed
    {
        if ($this->id === 'generate') {
            $body     = $this->body();
            $leagueId = $body['league_id'] ?? null;
            $seasonId = $body['season_id'] ?? null;
            if (!$leagueId || !$seasonId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'league_id and season_id required'];
            }
            return $this->db->generateH2HTournament($leagueId, $seasonId);
        }

        $body       = $this->body();
        $seasonId   = $body['season_id']     ?? null;
        $phase      = $body['phase']         ?? null;
        $leg        = isset($body['leg']) ? (int) $body['leg'] : 1;
        $homeTeamId = $body['home_team_id']  ?? null;
        $awayTeamId = $body['away_team_id']  ?? null;
        $matchdayId = $body['matchday_id']   ?? null;
        $groupId    = $body['group_id']      ?? null;
        $sortIndex  = isset($body['sort_index']) ? (int) $body['sort_index'] : 0;

        $validPhases = ['group', 'quarterfinal', 'semifinal', 'final'];
        if (!$seasonId || !$phase || !$homeTeamId || !$awayTeamId || !$matchdayId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id, phase, home_team_id, away_team_id, matchday_id required'];
        }
        if (!in_array($phase, $validPhases)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'phase must be one of: ' . implode(', ', $validPhases)];
        }

        $id = $this->generateGUID();
        $this->db->createH2HMatch($id, $seasonId, $phase, $leg, $homeTeamId, $awayTeamId, $matchdayId, $groupId ?: null, $sortIndex);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        $body   = $this->body();
        $fields = array_intersect_key($body, array_flip(['home_team_id', 'away_team_id', 'matchday_id', 'group_id', 'sort_index']));
        $this->db->updateH2HMatch($this->id, $fields);
        return ['status' => true];
    }

    protected function delete(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        $this->db->deleteH2HMatch($this->id);
        return ['status' => true];
    }
}
