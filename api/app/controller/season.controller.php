<?php

class SeasonController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id === 'active') {
            $season = $this->db->getActiveSeason();
            if (!$season) {
                http_response_code(404);
                return ['status' => false, 'message' => 'No active season found'];
            }
            return $season;
        }

        if ($this->id) {
            $season = $this->db->getSeasonById($this->id);
            if (!$season) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Season not found'];
            }
            return $season;
        }

        return $this->db->getSeasonList();
    }

    protected function post(): mixed
    {
        if ($this->id) return $this->methodNotAllowed();

        $body      = $this->body();
        $startDate = $body['start_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'start_date must be YYYY-MM-DD'];
        }

        $id = $this->generateGUID();
        $this->db->createSeason($id, $startDate);
        http_response_code(201);
        return ['status' => true, 'id' => $id];
    }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
