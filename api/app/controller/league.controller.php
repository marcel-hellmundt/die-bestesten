<?php

class LeagueController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'manager', 'PATCH' => 'admin'];

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
        // POST /league/:id/join — manager sends a join request (status='requested')
        if ($this->sub === 'join') {
            $leagueId  = $this->id;
            $managerId = $GLOBALS['auth_manager_id'];
            if (!$leagueId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Liga-ID fehlt'];
            }
            $league = $this->db->getLeagueById($leagueId);
            if (!$league) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Liga nicht gefunden'];
            }
            if (($league['visibility'] ?? 'public') === 'private') {
                http_response_code(403);
                return ['status' => false, 'message' => 'Diese Liga ist privat — Beitritt nur per Einladung möglich'];
            }
            $this->db->requestJoinLeague($managerId, $leagueId);

            $manager  = $this->db->getAuthManagerById($managerId);
            $adminIds = $this->db->getAdminManagerIds();
            foreach ($adminIds as $adminId) {
                $this->db->createNotification(
                    $adminId,
                    "Beitrittsanfrage: {$manager['manager_name']} möchte {$league['name']} beitreten",
                    null,
                    null
                );
            }
            $this->db->sendJoinRequestAdminEmail($manager['manager_name'], $league['name']);
            return ['status' => true];
        }

        // POST /league/:id/accept — manager accepts own invitation (status invited→active)
        if ($this->sub === 'accept') {
            $leagueId  = $this->id;
            $managerId = $GLOBALS['auth_manager_id'];
            if (!$leagueId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Liga-ID fehlt'];
            }
            $ok = $this->db->acceptLeagueInvite($managerId, $leagueId);
            if (!$ok) {
                http_response_code(409);
                return ['status' => false, 'message' => 'Keine ausstehende Einladung'];
            }
            return ['status' => true];
        }

        // POST /league/:id/decline — manager declines own invitation (invited→denied)
        if ($this->sub === 'decline') {
            $leagueId  = $this->id;
            $managerId = $GLOBALS['auth_manager_id'];
            if (!$leagueId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Liga-ID fehlt'];
            }
            $ok = $this->db->declineLeagueInvite($managerId, $leagueId);
            if (!$ok) {
                http_response_code(409);
                return ['status' => false, 'message' => 'Keine ausstehende Einladung'];
            }
            return ['status' => true];
        }

        // All remaining POST routes require admin
        if (!$this->isAdmin()) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Forbidden'];
        }

        // POST /league/:id/invite  { manager_id }  — admin invites a manager
        if ($this->sub === 'invite') {
            $leagueId  = $this->id;
            $body      = $this->body();
            $managerId = $body['manager_id'] ?? null;
            if (!$leagueId || !$managerId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Liga-ID und Manager-ID erforderlich'];
            }
            $league = $this->db->getLeagueById($leagueId);
            if (!$league) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Liga nicht gefunden'];
            }
            $this->db->inviteManagerToLeague($managerId, $leagueId);
            $this->db->createNotification(
                $managerId,
                "Einladung: {$league['name']}",
                null,
                null
            );
            return ['status' => true];
        }

        // POST /league/:id/approve  { manager_id }  — admin approves a join request
        if ($this->sub === 'approve') {
            $leagueId  = $this->id;
            $body      = $this->body();
            $managerId = $body['manager_id'] ?? null;
            if (!$leagueId || !$managerId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Liga-ID und Manager-ID erforderlich'];
            }
            $league = $this->db->getLeagueById($leagueId);
            if (!$league) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Liga nicht gefunden'];
            }
            $ok = $this->db->approveMembership($managerId, $leagueId);
            if ($ok) {
                $this->db->createNotification(
                    $managerId,
                    "Beitritt genehmigt: {$league['name']}",
                    null,
                    null
                );
            }
            return ['status' => true];
        }

        // POST /league/:id/deny  { manager_id }  — admin denies/cancels a membership
        if ($this->sub === 'deny') {
            $leagueId  = $this->id;
            $body      = $this->body();
            $managerId = $body['manager_id'] ?? null;
            if (!$leagueId || !$managerId) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Liga-ID und Manager-ID erforderlich'];
            }
            $this->db->denyMembership($managerId, $leagueId);
            return ['status' => true];
        }

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

        // POST /league/fix_rating  { league_id, team_id, matchday_id, field, value }
        if ($this->id === 'fix_rating') {
            $body       = $this->body();
            $leagueId   = $body['league_id']   ?? null;
            $teamId     = $body['team_id']      ?? null;
            $matchdayId = $body['matchday_id']  ?? null;
            $field      = $body['field']        ?? null;
            $value      = $body['value']        ?? null;
            if (!$leagueId || !$teamId || !$matchdayId || !$field || !isset($value)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Pflichtfelder fehlen'];
            }
            return $this->db->fixTeamRatingField($leagueId, $teamId, $matchdayId, $field, (int) $value);
        }

        return $this->methodNotAllowed();
    }

    protected function patch(): mixed
    {
        if (!$this->id || $this->id === 'mine') return $this->methodNotAllowed();

        $body = $this->body();

        if (array_key_exists('visibility', $body)) {
            $visibility = $body['visibility'];
            if (!in_array($visibility, ['public', 'private'], true)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'visibility muss "public" oder "private" sein'];
            }
            $this->db->updateLeagueVisibility($this->id, $visibility);
            return ['status' => true];
        }

        $divisionId = array_key_exists('division_id', $body) ? ($body['division_id'] ?: null) : 'MISSING';
        if ($divisionId === 'MISSING') {
            http_response_code(400);
            return ['status' => false, 'message' => 'division_id oder visibility erforderlich'];
        }

        $this->db->updateLeagueDivision($this->id, $divisionId);
        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
