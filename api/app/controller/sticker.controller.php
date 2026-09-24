<?php

class StickerController extends _BaseController
{
    // V0: nur die Album-Vorschau für die Parameter-Simulation (/klebrigsten/simulation) — Maintainer+.
    public static array $methodRoles = ['GET' => 'maintainer'];

    protected function get(): mixed
    {
        if ($this->id === 'album_preview') {
            return $this->db->getStickerAlbumPreview();
        }
        return $this->methodNotAllowed();
    }

    protected function post(): mixed   { return $this->methodNotAllowed(); }
    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }
}
