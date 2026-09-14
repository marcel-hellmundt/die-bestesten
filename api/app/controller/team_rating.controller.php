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
            $fromMatchday = isset($this->params['from_matchday_number'])
                ? (int) $this->params['from_matchday_number']
                : null;
            $toMatchday = isset($this->params['to_matchday_number'])
                ? (int) $this->params['to_matchday_number']
                : null;
            return $this->db->getSeasonStandings($seasonId, $fromMatchday, $toMatchday);
        }

        $matchdayNumber = isset($this->params['matchday_number'])
            ? (int) $this->params['matchday_number']
            : null;

        $result = $this->db->getTeamRatingsByActiveSeason($seasonId, $matchdayNumber);

        if ($result === false) {
            return ['matchday' => null, 'ratings' => []];
        }

        return $result;
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
