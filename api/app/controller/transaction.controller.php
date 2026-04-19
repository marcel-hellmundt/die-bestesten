<?php

class TransactionController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager'];

    protected function get(): mixed
    {
        $teamId = $this->params['team_id'] ?? null;
        if (!$teamId) {
            http_response_code(400);
            return ['status' => false, 'message' => 'team_id ist erforderlich'];
        }

        $ownerId = $this->db->getTeamManagerId($teamId);
        if (!$ownerId) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Team nicht gefunden'];
        }

        if ($ownerId !== $GLOBALS['auth_manager_id']) {
            http_response_code(403);
            return ['status' => false, 'message' => 'Zugriff verweigert'];
        }

        $transactions = $this->db->getTransactionsForTeam($teamId);
        $budget       = array_sum(array_column($transactions, 'amount'));

        return ['budget' => $budget, 'transactions' => $transactions];
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
