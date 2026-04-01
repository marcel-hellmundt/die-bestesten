<?php

class LeagueController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id) {
            $league = $this->db->getLeagueById($this->id);
            if (!$league) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Liga nicht gefunden'];
            }
            return $league;
        }

        return $this->db->getLeagueList();
    }

    protected function post(): mixed
    {
        // POST /league/migrate  { league_id }
        if ($this->id === 'migrate') {
            $body     = $this->body();
            $leagueId = $body['league_id'] ?? null;
            if (!$leagueId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'league_id ist erforderlich'];
            }
            return $this->db->migrateLeagueTeams($leagueId);
        }

        return $this->methodNotAllowed();
    }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
