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

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
