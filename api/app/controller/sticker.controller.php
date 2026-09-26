<?php

/**
 * "Die Klebrigsten" (Sticker-Album):
 *   GET  /sticker/album_preview      — live berechnetes Album für die Simulation (Maintainer+)
 *   GET  /sticker/album              — eingefrorenes Album der aktiven Saison (Auth)
 *   GET  /sticker/me                 — eigener Status: tägliches Pack vergeben, ungeöffnete Packs, Sammlung (Auth)
 *   GET  /sticker/collectors         — alle Manager mit Album + Fortschritt (Sammler-Rangliste, Auth)
 *   GET  /sticker/collection/:id     — Sammlung eines anderen Managers (Auth)
 *   GET  /sticker/shop               — Shop: Hauptliga + Lukaten-Guthaben dort (Auth)
 *   POST /sticker/shop/buy           — Lukaten-Angebot kaufen → Pack ungeöffnet ins Album, Mail an Admins (Auth)
 *   POST /sticker/shop/buy_eur       — Euro-Angebot kaufen → Packs sofort, Zahlung per PayPal.me, Admin bestätigt (Auth)
 *   GET  /sticker/shop/purchases     — alle Euro-Käufe der Saison (Admin)
 *   PATCH /sticker/shop/purchases/:id — Euro-Kauf bestätigen/stornieren (Admin)
 *   GET  /sticker/trade              — eigene Tauschangebote (offen ein-/ausgehend + Verlauf) (Auth)
 *   POST /sticker/trade              — Tauschangebot machen (Auth)
 *   PATCH /sticker/trade/:id         — Tauschangebot annehmen/ablehnen (Auth, Empfänger)
 *   DELETE /sticker/trade/:id        — eigenes Tauschangebot zurückziehen (Auth)
 *   POST /sticker/album/sync         — Album einfrieren/ergänzen (Admin)
 *   POST /sticker/pack/:id/open      — eigenes Pack öffnen (Auth)
 *   PATCH /sticker/pack/announced    — eigene Packs als groß angekündigt markieren (Auth)
 */
class StickerController extends _BaseController
{
    public static array $methodRoles = ['GET' => 'manager', 'POST' => 'manager', 'PATCH' => 'manager', 'DELETE' => 'manager'];

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
            return $this->db->getStickerCollectors($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'trade' && $this->sub === null) {
            return $this->db->getStickerTrades($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'shop' && $this->sub === null) {
            return $this->db->getStickerShop($GLOBALS['auth_manager_id']);
        }
        if ($this->id === 'shop' && $this->sub === 'purchases' && $this->sub_id === null) {
            if (!$this->isAdmin()) return $this->forbidden();
            return $this->db->getStickerEurPurchases();
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
        if ($this->id === 'shop' && $this->sub === 'buy') {
            $b = $this->body();
            if (!is_string($b['offer_key'] ?? null)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'offer_key erforderlich'];
            }
            $clubId = is_string($b['club_id'] ?? null) ? $b['club_id'] : null;
            return $this->stickerResult($this->db->buyStickerShopOffer($GLOBALS['auth_manager_id'], $b['offer_key'], $clubId));
        }
        if ($this->id === 'shop' && $this->sub === 'buy_eur') {
            $b = $this->body();
            if (!is_string($b['offer_key'] ?? null)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'offer_key erforderlich'];
            }
            $clubId = is_string($b['club_id'] ?? null) ? $b['club_id'] : null;
            return $this->stickerResult($this->db->buyStickerShopEur($GLOBALS['auth_manager_id'], $b['offer_key'], $clubId));
        }
        if ($this->id === 'trade' && $this->sub === null) {
            $b = $this->body();
            if (!is_string($b['to_manager_id'] ?? null) || !is_array($b['give'] ?? null) || !is_array($b['get'] ?? null)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'to_manager_id, give (Array) und get (Array) erforderlich'];
            }
            return $this->stickerResult($this->db->createStickerTrade($GLOBALS['auth_manager_id'], $b['to_manager_id'], $b['give'], $b['get']));
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
        if ($this->id === 'shop' && $this->sub === 'purchases' && $this->sub_id !== null) {
            if (!$this->isAdmin()) return $this->forbidden();
            $action = $this->body()['action'] ?? null;
            if (!in_array($action, ['confirm', 'cancel'], true)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'action (confirm|cancel) erforderlich'];
            }
            return $this->stickerResult($this->db->handleStickerEurPurchase($GLOBALS['auth_manager_id'], $this->sub_id, $action));
        }
        if ($this->id === 'trade' && $this->sub !== null) {
            $action = $this->body()['action'] ?? null;
            if (!in_array($action, ['accept', 'decline'], true)) {
                http_response_code(400);
                return ['status' => false, 'message' => 'action (accept|decline) erforderlich'];
            }
            return $this->stickerResult($this->db->respondStickerTrade($GLOBALS['auth_manager_id'], $this->sub, $action));
        }
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
    protected function delete(): mixed
    {
        if ($this->id === 'trade' && $this->sub !== null) {
            return $this->stickerResult($this->db->cancelStickerTrade($GLOBALS['auth_manager_id'], $this->sub));
        }
        return $this->methodNotAllowed();
    }

    /** ['error' => Code, 'message'] → HTTP-Fehler, sonst {status: true, …} */
    private function stickerResult(array $result): array
    {
        if (isset($result['error'])) {
            http_response_code($result['error']);
            return ['status' => false, 'message' => $result['message']];
        }
        return ['status' => true] + $result;
    }

    private function forbidden(): array
    {
        http_response_code(403);
        return ['status' => false, 'message' => 'Keine Berechtigung'];
    }
}
