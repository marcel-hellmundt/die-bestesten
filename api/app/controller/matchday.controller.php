<?php

class MatchdayController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'admin', 'PATCH' => 'admin'];

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

        return $this->db->getMatchdayList($this->params['season_id'] ?? null);
    }

    protected function post(): mixed
    {
        if ($this->id === 'migrate') {
            return $this->db->migrateMatchday();
        }

        if ($this->id !== null) return $this->methodNotAllowed();

        $body        = $this->body();
        $seasonId    = $body['season_id']    ?? null;
        $number      = isset($body['number']) ? (int) $body['number'] : null;
        $startDate   = $body['start_date']   ?? null;
        $kickoffDate = $body['kickoff_date'] ?? null;

        if (!$seasonId || !$number || !$startDate || !$kickoffDate) {
            http_response_code(400);
            return ['status' => false, 'message' => 'season_id, number, start_date und kickoff_date sind erforderlich'];
        }

        try {
            $id = $this->db->createMatchday($seasonId, $number, $startDate, $kickoffDate);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            return ['status' => false, 'message' => $e->getMessage()];
        } catch (\PDOException $e) {
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
            http_response_code(400);
            return ['status' => false, 'message' => 'Feld "completed" fehlt'];
        }

        $matchday = $this->db->getMatchdayById($this->id);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Matchday not found'];
        }

        $completed = (bool) $body['completed'];
        $teamRatings  = 0;
        $achievements = 0;
        if ($completed) {
            $teamRatings = $this->db->finalizeMatchday($this->id);
            $achResult   = $this->db->evaluateAchievements(true);
            $achievements = $achResult['count'];
            $this->db->createMatchdayCompletedNotifications((int) $matchday['number']);
            $this->db->sendMatchdayCompletedAdminEmail($this->id, $teamRatings, $achResult['new'], (int) $matchday['number']);
        }
        $this->db->updateMatchdayCompleted($this->id, $completed);
        return ['status' => true, 'team_ratings' => $teamRatings, 'achievements' => $achievements];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
