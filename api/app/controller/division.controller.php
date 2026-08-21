<?php

class DivisionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'PATCH' => 'admin'];

    protected function get(): mixed
    {
        if ($this->id) {
            $division = $this->db->getDivisionById($this->id);
            if (!$division) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Division not found'];
            }
            return $division;
        }

        return $this->db->getDivisionList();
    }

    protected function post(): mixed { return $this->methodNotAllowed(); }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $body = $this->body();
        if (!isset($body['starting_budget']) || !isset($body['points_bonus'])) {
            http_response_code(400);
            return ['status' => false, 'message' => 'starting_budget und points_bonus erforderlich'];
        }

        $startingBudget = (int) $body['starting_budget'];
        $pointsBonus    = (int) $body['points_bonus'];
        if ($startingBudget <= 0 || $pointsBonus <= 0) {
            http_response_code(422);
            return ['status' => false, 'message' => 'starting_budget und points_bonus müssen größer 0 sein'];
        }

        $this->db->updateDivisionConfig($this->id, $startingBudget, $pointsBonus);
        return ['status' => true];
    }

    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
