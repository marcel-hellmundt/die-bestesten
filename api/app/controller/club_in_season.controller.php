<?php

class ClubInSeasonController extends _BaseController
{
    public static array $publicMethods = ['GET'];

    protected function get(): mixed
    {
        $clubId   = $this->params['club_id']   ?? null;
        $seasonId = $this->params['season_id'] ?? null;

        if ($clubId) {
            return $this->db->getClubInSeasonByClub($clubId);
        }
        if ($seasonId) {
            return $this->db->getClubInSeasonBySeason($seasonId);
        }

        http_response_code(400);
        return ['status' => false, 'message' => 'club_id or season_id query parameter required'];
    }

    protected function post(): mixed { return $this->methodNotAllowed(); }

    protected function patch(): mixed
    {
        if (!$this->id) {
            http_response_code(400);
            return ['status' => false, 'message' => 'ID required'];
        }

        $body = $this->body();
        $divisionId = $body['division_id'] ?? null;
        $position   = array_key_exists('position', $body) ? $body['position'] : false;

        if (!$divisionId && $position === false) {
            http_response_code(400);
            return ['status' => false, 'message' => 'No fields to update'];
        }

        $this->db->updateClubInSeason($this->id, $divisionId, $position === false ? null : $position);
        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
