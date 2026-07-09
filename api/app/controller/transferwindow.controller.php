<?php

class TransferwindowController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'maintainer', 'PATCH' => 'admin', 'DELETE' => 'admin'];

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
            $this->params['season_id']   ?? null,
            $this->params['division_id'] ?? null
        );
    }

    protected function post(): mixed
    {
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

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();

        $tw = $this->db->getTransferwindowById($this->id);
        if (!$tw) {
            http_response_code(404);
            return ['status' => false, 'message' => 'Transferwindow not found'];
        }

        $body = $this->body();
        if (!array_key_exists('start_date', $body) && !array_key_exists('end_date', $body)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'start_date oder end_date erforderlich'];
        }
        $startDate = $body['start_date'] ?? $tw['start_date'];
        $endDate   = $body['end_date']   ?? $tw['end_date'];

        $matchday = $this->db->getMatchdayById($tw['matchday_id']);
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

        $existing = $this->db->getTransferwindowList($tw['matchday_id'], null);
        foreach ($existing as $other) {
            if ($other['id'] === $this->id) continue;
            $exStart = new DateTime($other['start_date']);
            $exEnd   = new DateTime($other['end_date']);
            if ($newStart < $exEnd && $newEnd > $exStart) {
                http_response_code(409);
                return ['status' => false, 'message' => 'Überschneidung mit bestehendem Transferfenster (' . $other['start_date'] . ' – ' . $other['end_date'] . ')'];
            }
        }

        return $this->db->updateTransferwindow($this->id, $startDate, $endDate);
    }

    protected function delete(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        return $this->db->deleteTransferwindow($this->id);
    }
}
