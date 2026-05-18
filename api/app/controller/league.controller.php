<?php

class LeagueController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin', 'PATCH' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id === 'mine') {
            $league = $this->db->getMyLeague();
            if (!$league) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Liga nicht konfiguriert'];
            }
            return $league;
        }

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

        // POST /league/validate_ratings  { league_id }
        if ($this->id === 'validate_ratings') {
            $body     = $this->body();
            $leagueId = $body['league_id'] ?? null;
            if (!$leagueId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'league_id ist erforderlich'];
            }
            return $this->db->validateLeagueRatings($leagueId);
        }

        return $this->methodNotAllowed();
    }

    protected function patch(): mixed
    {
        if (!$this->id || $this->id === 'mine') return $this->methodNotAllowed();

        $body       = $this->body();
        $divisionId = array_key_exists('division_id', $body) ? ($body['division_id'] ?: null) : 'MISSING';
        if ($divisionId === 'MISSING') {
            http_response_code(400);
            return ['status' => false, 'message' => 'division_id required (use null to clear)'];
        }

        $this->db->updateLeagueDivision($this->id, $divisionId);
        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
