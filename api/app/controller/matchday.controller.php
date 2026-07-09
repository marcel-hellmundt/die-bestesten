<?php

class MatchdayController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'admin', 'PATCH' => 'admin', 'DELETE' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id) {
            $matchday = $this->db->getMatchdayById($this->id);
            if (!$matchday) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Matchday not found'];
            }
            return $matchday;
        }

        return $this->db->getMatchdayList($this->params['season_id'] ?? null, $this->params['division_id'] ?? null);
    }

    protected function post(): mixed
    {
        if ($this->id !== null) return $this->methodNotAllowed();

        $body        = $this->body();
        $seasonId    = $body['season_id']    ?? null;
        $number      = isset($body['number']) ? (int) $body['number'] : null;
        $startDate   = $body['start_date']   ?? null;
        $kickoffDate = $body['kickoff_date'] ?? null;
        $divisionId  = $body['division_id']  ?? null;

        if (!$seasonId || !$number || !$startDate || !$kickoffDate) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id, number, start_date und kickoff_date sind erforderlich'];
        }

        try {
            $id = $this->db->createMatchday($seasonId, $number, $startDate, $kickoffDate, $divisionId);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            return ['status' => false, 'message' => $e->getMessage()];
        } catch (\PDOException $e) {
            $mysqlErrorCode = $e->errorInfo[1] ?? null;
            if ($e->getCode() === '23000' && $mysqlErrorCode === 1452) {
                http_response_code(422);
                return ['status' => false, 'message' => 'Ungültige division_id'];
            }
            if ($e->getCode() === '23000') {
                http_response_code(409);
                return ['status' => false, 'message' => 'Spieltag existiert bereits für diese Division'];
            }
            throw $e;
        }

        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $body = $this->body();

        if (!array_key_exists('completed', $body)) {
            $editable = array_intersect_key($body, array_flip(['number', 'start_date', 'kickoff_date']));
            if (empty($editable)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'Feld "completed" fehlt'];
            }
            if (isset($editable['number'])) {
                $editable['number'] = (int) $editable['number'];
            }
            return $this->db->updateMatchdayFields($this->id, $editable);
        }

        $matchday = $this->db->getMatchdayById($this->id);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Matchday not found'];
        }

        $completed = (bool) $body['completed'];
        $teamRatings  = 0;
        $achievements = 0;
        $seasonAwards = null;
        if ($completed) {
            $teamRatings = $this->db->finalizeMatchday($this->id);
            $achResult   = $this->db->evaluateAchievements(true);
            $achievements = $achResult['count'];
            $this->db->createMatchdayCompletedNotifications((int) $matchday['number']);
            $this->db->sendMatchdayCompletedAdminEmail($this->id, $teamRatings, $achResult['new'], (int) $matchday['number']);
            if ((int) $matchday['number'] === 34) {
                $leagueId = $GLOBALS['auth_league_id'] ?? null;
                if ($leagueId) {
                    $seasonAwards = $this->db->concludeSeasonForLeague($leagueId, $matchday['season_id']);
                }
            }
        }
        $this->db->updateMatchdayCompleted($this->id, $completed);
        return ['status' => true, 'team_ratings' => $teamRatings, 'achievements' => $achievements, 'season_awards' => $seasonAwards];
    }

    protected function delete(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        return $this->db->deleteMatchday($this->id);
    }
}
