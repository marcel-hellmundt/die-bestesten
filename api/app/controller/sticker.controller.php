<?php

/**
 * "Die Klebrigsten" (Sticker-Album):
 *   GET  /sticker/album_preview      — live berechnetes Album für die Simulation (Maintainer+)
 *   GET  /sticker/album              — eingefrorenes Album der aktiven Saison (Auth)
 *   GET  /sticker/me                 — eigener Status: tägliches Pack vergeben, ungeöffnete Packs, Sammlung (Auth)
 *   POST /sticker/album/sync         — Album einfrieren/ergänzen (Admin) *   POST /sticker/pack/:id/open      — eigenes Pack öffnen (Auth)
 */
class StickerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager'];

    protected function get(): mixed
    {
        if ($this->id === 'album_preview') {
            if (!$this->isMaintainer()) return $this->forbidden();
            return $this->db->getStickerAlbumPreview();
        }
        if ($this->id === 'album' && $this->sub === null) {
            return $this->db->getStickerAlbum();
        }
        if ($this->id === 'me' && $this->sub === null) {
            return $this->db->getMyStickerState($GLOBALS['auth_manager_id']);
        }
        return $this->methodNotAllowed();
    }

    protected function post(): mixed
    {
        if ($this->id === 'album' && $this->sub === 'sync') {
            if (!$this->isAdmin()) return $this->forbidden();
            return ['status' => true] + $this->db->syncStickerAlbum();
        }
        if ($this->id === 'pack' && $this->sub !== null && $this->sub_id === 'open') {
            $result = $this->db->openStickerPack($GLOBALS['auth_manager_id'], $this->sub);
            if (isset($result['error'])) {
                http_response_code($result['error']);
                return ['status' => false, 'message' => $result['message']];
            }
            return ['status' => true] + $result;
        }
        return $this->methodNotAllowed();
    }

    protected function patch(): mixed  { return $this->methodNotAllowed(); }
    protected function delete(): mixed { return $this->methodNotAllowed(); }

    private function forbidden(): array
    {
        http_response_code(403);
        return ['status' => false, 'message' => 'Keine Berechtigung'];
    }
}
