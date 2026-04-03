<?php

class TeamRatingController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        $seasonId = $this->params['season_id'] ?? null;

        if (!$seasonId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id ist erforderlich'];
        }

        if ($this->id === 'season') {
            return $this->db->getSeasonStandings($seasonId);
        }

        $result = $this->db->getTeamRatingsByActiveSeason($seasonId);

        if ($result === false) {
            return ['matchday' => null, 'ratings' => []];
        }

        return $result;
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
