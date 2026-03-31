<?php

class TransferwindowController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'maintainer'];

    private static array $roleLevels = ['guest' => 0, 'manager' => 1, 'maintainer' => 2, 'admin' => 3];

    protected function get(): mixed
    {
        if ($this->id) {
            $tw = $this->db->getTransferwindowById($this->id);
            if (!$tw) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Transferwindow not found'];
            }
            return $tw;
        }

        return $this->db->getTransferwindowList(
            $this->params['matchday_id'] ?? null,
            $this->params['season_id']   ?? null
        );
    }

    protected function post(): mixed
    {
        if ($this->id === 'migrate') {
            if ((self::$roleLevels[$GLOBALS['auth_role']] ?? 0) < self::$roleLevels['admin']) {
                http_response_code(403);
                return ['status' => false, 'message' => 'Forbidden'];
            }
            return $this->db->migrateTransferwindow();
        }

        if ($this->id !== null) return $this->methodNotAllowed();

        $body       = $this->body();
        $matchdayId = $body['matchday_id'] ?? null;
        $startDate  = $body['start_date']  ?? null;
        $endDate    = $body['end_date']    ?? null;

        if (!$matchdayId || !$startDate || !$endDate) {
            http_response_code(400);
            return ['status' => false, 'message' => 'matchday_id, start_date und end_date sind erforderlich'];
        }

        $matchday = $this->db->getMatchdayById($matchdayId);
        if (!$matchday) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Spieltag nicht gefunden'];
        }

        $newStart  = new DateTime($startDate);
        $newEnd    = new DateTime($endDate);
        $mdStart   = new DateTime($matchday['start_date']);
        $mdKickoff = $matchday['kickoff_date'] ? new DateTime($matchday['kickoff_date']) : null;

        if ($newStart >= $newEnd) {
            http_response_code(422);
            return ['status' => false, 'message' => 'Start muss vor Ende liegen'];
        }

        if ($newStart < $mdStart) {
            http_response_code(422);
            return ['status' => false, 'message' => 'Transferfenster darf nicht vor Spieltag-Start (' . $matchday['start_date'] . ') öffnen'];
        }

        if ($mdKickoff && $newEnd > $mdKickoff) {
            http_response_code(422);
            return ['status' => false, 'message' => 'Transferfenster muss vor dem Anpfiff (' . $matchday['kickoff_date'] . ') schließen'];
        }

        $existing = $this->db->getTransferwindowList($matchdayId, null);
        foreach ($existing as $tw) {
            $exStart = new DateTime($tw['start_date']);
            $exEnd   = new DateTime($tw['end_date']);
            if ($newStart < $exEnd && $newEnd > $exStart) {
                http_response_code(409);
                return ['status' => false, 'message' => 'Überschneidung mit bestehendem Transferfenster (' . $tw['start_date'] . ' – ' . $tw['end_date'] . ')'];
            }
        }

        return $this->db->createTransferwindow($matchdayId, $startDate, $endDate);
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
