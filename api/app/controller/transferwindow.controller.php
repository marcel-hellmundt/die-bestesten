<?php

class TransferwindowController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'POST' => 'admin'];

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
        if ($this->id !== 'migrate') return $this->methodNotAllowed();
        return $this->db->migrateTransferwindow();
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
