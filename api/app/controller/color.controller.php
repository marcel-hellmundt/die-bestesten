<?php

class ColorController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'guest', 'PATCH' => 'admin'];

    protected function get(): mixed
    {
        return $this->db->getColors();
    }

    protected function patch(): mixed
    {
        if (!$this->id) return $this->methodNotAllowed();
        $body = $this->body();
        $hex  = $body['hex'] ?? null;
        if (!$hex || !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            http_response_code(400);
            return ['status' => false, 'message' => 'hex must be #rrggbb'];
        }
        $this->db->updateColorHex($this->id, $hex); // $this->id = color name (e.g. 'red')
        return ['status' => true];
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
