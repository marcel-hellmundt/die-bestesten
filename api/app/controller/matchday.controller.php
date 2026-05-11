<?php

class MatchdayController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin', 'PATCH' => 'admin'];

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
        if ($this->id !== 'migrate') return $this->methodNotAllowed();

        return $this->db->migrateMatchday();
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
