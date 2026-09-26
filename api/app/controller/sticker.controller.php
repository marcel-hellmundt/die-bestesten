<?php

/**
 * "Die Klebrigsten" (Sticker-Album):
 *   GET  /sticker/album_preview      — live berechnetes Album für die Simulation (Maintainer+)
 *   GET  /sticker/album              — eingefrorenes Album der aktiven Saison (Auth)
 *   GET  /sticker/me                 — eigener Status: tägliches Pack vergeben, ungeöffnete Packs, Sammlung (Auth)
 *   GET  /sticker/collectors         — alle Manager mit Album + Fortschritt (Sammler-Rangliste, Auth)
 *   GET  /sticker/collection/:id     — Sammlung eines anderen Managers (Auth)
 *   GET  /sticker/shop               — Shop: Hauptliga + Lukaten-Guthaben dort (Auth)
 *   POST /sticker/album/sync         — Album einfrieren/ergänzen (Admin)
 *   POST /sticker/pack/:id/open      — eigenes Pack öffnen (Auth)
 *   PATCH /sticker/pack/announced    — eigene Packs als groß angekündigt markieren (Auth)
 */
class StickerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'PATCH' => 'manager'];

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
        if ($this->id === 'collectors' && $this->sub === null) {
            return $this->db->getStickerCollectors();
        }
        if ($this->id === 'shop' && $this->sub === null) {
            return $this->db->getStickerShop($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'collection' && $this->sub !== null) {
            $result = $this->db->getStickerCollectionOf($this->sub);
            if ($result === null) {
                http_response_code(404);
                return ['status' => false, 'message' => 'Dieser Manager hat kein Sammelalbum'];
            }
            return $result;
        }
        return $this->methodNotAllowed();
    }

    protected function post(): mixed
    {
        if ($this->id === 'album' && $this->sub === 'sync') {
            if (!$this->isAdmin()) return $this->forbidden();
            $sync = $this->db->syncStickerAlbum();
            // bisherige Meilensteine + Spieltagssiege der Saison nachträglich belohnen (idempotent)
            $packs = $sync['season_id'] ? $this->db->backfillStickerPacks($sync['season_id']) : ['milestone' => 0, 'matchday_best' => 0];
            return ['status' => true] + $sync + ['packs' => $packs];
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

    protected function patch(): mixed
    {
        if ($this->id === 'pack' && $this->sub === 'announced') {
            $ids = $this->body()['ids'] ?? null;
            if (!is_array($ids) || count($ids) > 500) {
                http_response_code(400);
                return ['status' => false, 'message' => 'ids (Array von Pack-IDs) erforderlich'];
            }
            try {
                return ['status' => true, 'updated' => $this->db->markStickerPacksAnnounced($GLOBALS['auth_manager_id'], $ids)];
            } catch (\Throwable $e) {
                return ['status' => true, 'updated' => 0]; // Spalte announced_at fehlt noch (Migration)
            }
        }
        return $this->methodNotAllowed();
    }
    protected function delete(): mixed { return $this->methodNotAllowed(); }

    private function forbidden(): array
    {
        http_response_code(403);
        return ['status' => false, 'message' => 'Keine Berechtigung'];
    }
}
