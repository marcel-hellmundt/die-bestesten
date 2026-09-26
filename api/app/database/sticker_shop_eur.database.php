<?php

/**
 * "Die Klebrigsten" — Shop: Packs gegen Euro per PayPal.me (privates Konto, keine Zahlungs-API).
 * Ablauf: Kauf anlegen (pending, Kauf-Code) → Packs sofort ungeöffnet ins Album → Käufer zahlt per
 * PayPal.me-Link (Betrag vorausgefüllt, Code als Nachricht) → ein Admin bestätigt die Zahlung (paid) oder
 * storniert (cancelled: Packs + daraus gezogene Karten werden gelöscht). Solange pending, sind Karten aus diesen
 * Packs nicht tauschbar (siehe stickerLockedJoin()) — so bleibt ein Storno sauber, niemand sonst ist betroffen.
 */
trait StickerShopEurTrait
{
    private const EUR_MAX_PENDING = 3; // offene (unbezahlte) Käufe je Manager

    /** Euro-Angebote — maßgeblich sind die Preise hier (shop.model.ts muss passen). */
    protected function stickerShopEurOffers(): array
    {
        return [
            'e-starter' => ['name' => 'Starter',      'price_cents' => 199, 'packs' => 10, 'size' => 3, 'guaranteed_new' => 1, 'club' => false, 'once' => true],
            'e-handful' => ['name' => 'Handvoll',     'price_cents' => 299, 'packs' => 5,  'size' => 3, 'guaranteed_new' => 1, 'club' => false, 'once' => false],
            'e-stack'   => ['name' => 'Stapel',       'price_cents' => 499, 'packs' => 12, 'size' => 3, 'guaranteed_new' => 1, 'club' => false, 'once' => false],
            'e-crate'   => ['name' => 'Kiste',        'price_cents' => 999, 'packs' => 30, 'size' => 3, 'guaranteed_new' => 1, 'club' => false, 'once' => false],
            'e-club'    => ['name' => 'Vereins-Pack', 'price_cents' => 199, 'packs' => 1,  'size' => 5, 'guaranteed_new' => 5, 'club' => true,  'once' => false],
        ];
    }

    /** PayPal.me-Name des Empfängers (privates Konto) — per .env überschreibbar. */
    private function paypalMeName(): string
    {
        return $_ENV['PAYPAL_ME'] ?? 'krausemarcel';
    }

    private function paypalMeUrl(int $amountCents): string
    {
        return 'https://paypal.me/' . rawurlencode($this->paypalMeName()) . '/' . number_format($amountCents / 100, 2, '.', '') . 'EUR';
    }

    private function formatEur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    /** Tabelle + Spalte vorhanden? (Migration migrate_sticker_shop_eur.sql) — je Request einmal geprüft. */
    private ?bool $eurReadyCache = null;
    private function stickerEurReady(): bool
    {
        if ($this->eurReadyCache !== null) return $this->eurReadyCache;
        try {
            $this->con->query("SELECT 1 FROM sticker_eur_purchase LIMIT 1");
            $this->con->query("SELECT eur_purchase_id FROM sticker_pack LIMIT 1");
            return $this->eurReadyCache = true;
        } catch (\Throwable $e) {
            return $this->eurReadyCache = false;
        }
    }

    /**
     * SQL-Bausteine, um Karten aus noch unbezahlten Euro-Käufen zu erkennen (nicht tauschbar):
     * join = an sticker_pull p anzuhängen, locked = Ausdruck (1 = gesperrt). Ohne Migration: nichts gesperrt.
     */
    private function stickerLockedJoin(string $pullAlias = 'p'): array
    {
        if (!$this->stickerEurReady()) return ['join' => '', 'locked' => '0'];
        return [
            'join'   => " LEFT JOIN sticker_pack lk_sp ON lk_sp.id = {$pullAlias}.pack_id
                          LEFT JOIN sticker_eur_purchase lk_ep ON lk_ep.id = lk_sp.eur_purchase_id ",
            'locked' => "(lk_ep.status IS NOT NULL AND lk_ep.status = 'pending')",
        ];
    }

    /** Für GET /sticker/shop: eigene offene Käufe (zum Bezahlen) + ob der Starter noch verfügbar ist. */
    public function getStickerEurState(string $managerId, ?string $seasonId): array
    {
        $state = ['available' => $this->stickerEurReady(), 'paypal_me' => $this->paypalMeName(), 'starter_available' => false, 'pending' => []];
        if (!$state['available'] || $seasonId === null) return $state;

        $q = $this->con->prepare(
            "SELECT id, offer_key, amount_cents, code, status, created_at FROM sticker_eur_purchase
             WHERE manager_id = ? AND season_id = ? AND status != 'cancelled' ORDER BY created_at DESC"
        );
        $q->execute([$managerId, $seasonId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $state['starter_available'] = !array_filter($rows, fn($r) => $r['offer_key'] === 'e-starter');
        $state['pending'] = array_values(array_map(fn($r) => [
            'id' => $r['id'], 'offer_key' => $r['offer_key'], 'amount_cents' => (int) $r['amount_cents'],
            'code' => $r['code'], 'created_at' => $r['created_at'], 'paypal_url' => $this->paypalMeUrl((int) $r['amount_cents']),
        ], array_filter($rows, fn($r) => $r['status'] === 'pending')));
        return $state;
    }

    /**
     * POST /sticker/shop/buy_eur — Euro-Angebot kaufen: Kauf (pending) + Packs sofort anlegen, Admins informieren.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['purchase_id','code','amount_cents','paypal_url','packs'].
     */
    public function buyStickerShopEur(string $managerId, string $offerKey, ?string $clubId): array
    {
        if (!$this->stickerEurReady()) return ['error' => 409, 'message' => 'Euro-Käufe sind noch nicht eingerichtet'];
        $offer = $this->stickerShopEurOffers()[$offerKey] ?? null;
        if (!$offer) return ['error' => 422, 'message' => 'Unbekanntes Angebot'];
        if (!$this->isStickerEnabledForManager($managerId)) return ['error' => 409, 'message' => 'Du spielst in keiner Liga mit Sticker-Album'];
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null || !$this->stickerAlbumReady($seasonId)) return ['error' => 409, 'message' => 'Album der Saison existiert noch nicht'];

        $clubName = null;
        if ($offer['club']) {
            if (!$clubId) return ['error' => 422, 'message' => 'Bitte einen Verein wählen'];
            $clubName = $this->stickerShopClubName($seasonId, $clubId);
            if ($clubName === null) return ['error' => 422, 'message' => 'Verein ist nicht im Album'];
        } else {
            $clubId = null;
        }

        $sq = $this->con->prepare(
            "SELECT offer_key, status FROM sticker_eur_purchase WHERE manager_id = ? AND season_id = ? AND status != 'cancelled'"
        );
        $sq->execute([$managerId, $seasonId]);
        $existing = $sq->fetchAll(PDO::FETCH_ASSOC);
        if ($offer['once'] && array_filter($existing, fn($r) => $r['offer_key'] === $offerKey)) {
            return ['error' => 409, 'message' => 'Das Starter-Angebot gibt es nur einmal pro Saison'];
        }
        if (count(array_filter($existing, fn($r) => $r['status'] === 'pending')) >= self::EUR_MAX_PENDING) {
            return ['error' => 409, 'message' => 'Bitte erst deine offenen Zahlungen erledigen'];
        }

        $this->con->beginTransaction();
        try {
            $purchaseId = $this->con->query("SELECT UUID()")->fetchColumn();
            $code = $this->newStickerEurCode();
            $this->con->prepare(
                "INSERT INTO sticker_eur_purchase (id, manager_id, season_id, offer_key, amount_cents, code) VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$purchaseId, $managerId, $seasonId, $offerKey, $offer['price_cents'], $code]);

            $ins = $this->con->prepare(
                "INSERT INTO sticker_pack (manager_id, season_id, source, source_key, club_id, eur_purchase_id, size, guaranteed_new)
                 VALUES (?, ?, 'shop', ?, ?, ?, ?, ?)"
            );
            for ($i = 1; $i <= $offer['packs']; $i++) {
                $ins->execute([$managerId, $seasonId, "shop:{$offerKey}:{$purchaseId}:{$i}", $clubId, $purchaseId, $offer['size'], $offer['guaranteed_new']]);
            }
            $this->con->commit();
        } catch (\Throwable $e) {
            if ($this->con->inTransaction()) $this->con->rollBack();
            error_log('buyStickerShopEur: ' . $e->getMessage());
            return ['error' => 409, 'message' => 'Kauf konnte nicht angelegt werden'];
        }

        $this->informAdminsOfStickerEur($managerId, $offer, $clubName, $code);
        return [
            'purchase_id'  => $purchaseId,
            'code'         => $code,
            'amount_cents' => $offer['price_cents'],
            'paypal_url'   => $this->paypalMeUrl($offer['price_cents']),
            'packs'        => $offer['packs'],
        ];
    }

    /** Kurzer, gut abtippbarer Kauf-Code (ohne 0/O/1/I), eindeutig. */
    private function newStickerEurCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $check = $this->con->prepare("SELECT 1 FROM sticker_eur_purchase WHERE code = ?");
        do {
            $code = 'DK-';
            for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $check->execute([$code]);
        } while ($check->fetchColumn());
        return $code;
    }

    /** GET /sticker/shop/purchases — alle Euro-Käufe der aktiven Saison (Admin): offene zuerst. */
    public function getStickerEurPurchases(): array
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$this->stickerEurReady() || $seasonId === null) return ['available' => $this->stickerEurReady(), 'purchases' => []];
        $q = $this->con->prepare(
            "SELECT ep.id, ep.manager_id, m.manager_name, ep.offer_key, ep.amount_cents, ep.code, ep.status,
                    ep.created_at, ep.handled_at, hm.manager_name AS handled_by_name,
                    (SELECT COUNT(*) FROM sticker_pack sp WHERE sp.eur_purchase_id = ep.id) AS packs_total,
                    (SELECT COUNT(*) FROM sticker_pack sp WHERE sp.eur_purchase_id = ep.id AND sp.opened_at IS NOT NULL) AS packs_opened
             FROM sticker_eur_purchase ep
             JOIN manager m ON m.id = ep.manager_id
             LEFT JOIN manager hm ON hm.id = ep.handled_by
             WHERE ep.season_id = ?
             ORDER BY ep.status = 'pending' DESC, ep.created_at DESC"
        );
        $q->execute([$seasonId]);
        $offers = $this->stickerShopEurOffers();
        return ['available' => true, 'purchases' => array_map(fn($r) => [
            'id' => $r['id'], 'manager_id' => $r['manager_id'], 'manager_name' => $r['manager_name'],
            'offer_key' => $r['offer_key'], 'offer_name' => $offers[$r['offer_key']]['name'] ?? $r['offer_key'],
            'amount_cents' => (int) $r['amount_cents'], 'code' => $r['code'], 'status' => $r['status'],
            'created_at' => $r['created_at'], 'handled_at' => $r['handled_at'], 'handled_by_name' => $r['handled_by_name'],
            'packs_total' => (int) $r['packs_total'], 'packs_opened' => (int) $r['packs_opened'],
        ], $q->fetchAll(PDO::FETCH_ASSOC))];
    }

    /**
     * PATCH /sticker/shop/purchases/:id — Admin bestätigt die Zahlung (confirm → paid, Karten werden tauschbar)
     * oder storniert (cancel → cancelled, Packs + daraus gezogene Karten werden gelöscht). Nur offene Käufe.
     */
    public function handleStickerEurPurchase(string $adminId, string $purchaseId, string $action): array
    {
        if (!$this->stickerEurReady()) return ['error' => 409, 'message' => 'Euro-Käufe sind noch nicht eingerichtet'];
        $q = $this->con->prepare("SELECT id, manager_id, offer_key, amount_cents, code, status FROM sticker_eur_purchase WHERE id = ?");
        $q->execute([$purchaseId]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['error' => 404, 'message' => 'Kauf nicht gefunden'];
        if ($p['status'] !== 'pending') return ['error' => 409, 'message' => 'Kauf ist nicht mehr offen'];

        $name = $this->stickerShopEurOffers()[$p['offer_key']]['name'] ?? $p['offer_key'];
        $amount = $this->formatEur((int) $p['amount_cents']);

        $this->con->beginTransaction();
        try {
            $up = $this->con->prepare(
                "UPDATE sticker_eur_purchase SET status = ?, handled_at = NOW(), handled_by = ? WHERE id = ? AND status = 'pending'"
            );
            $up->execute([$action === 'confirm' ? 'paid' : 'cancelled', $adminId, $purchaseId]);
            if ($up->rowCount() === 0) {
                $this->con->rollBack();
                return ['error' => 409, 'message' => 'Kauf ist nicht mehr offen'];
            }
            // Storno: Packs löschen — gezogene Karten hängen per ON DELETE CASCADE daran (tauschen war gesperrt)
            if ($action === 'cancel') {
                $this->con->prepare("DELETE FROM sticker_pack WHERE eur_purchase_id = ?")->execute([$purchaseId]);
            }
            $this->con->commit();
        } catch (\Throwable $e) {
            if ($this->con->inTransaction()) $this->con->rollBack();
            throw $e;
        }

        try {
            if ($action === 'confirm') {
                $this->createNotification($p['manager_id'], "Zahlung bestätigt – $name",
                    "Danke! $amount ({$p['code']}) sind angekommen. Die Karten aus dem Kauf kannst du jetzt auch tauschen.", $adminId);
            } else {
                $this->createNotification($p['manager_id'], "Kauf storniert – $name",
                    "Für {$p['code']} ist keine Zahlung eingegangen, der Kauf wurde storniert und die Packs daraus entfernt.", $adminId);
            }
        } catch (\Throwable $e) {
            error_log('handleStickerEurPurchase notify: ' . $e->getMessage());
        }
        return ['status' => $action === 'confirm' ? 'paid' : 'cancelled'];
    }

    /** Mail + In-App-Benachrichtigung an alle Admins: neuer Euro-Kauf, Zahlung prüfen. */
    private function informAdminsOfStickerEur(string $managerId, array $offer, ?string $clubName, string $code): void
    {
        try {
            $n = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
            $n->execute([$managerId]);
            $managerName = (string) $n->fetchColumn();
            $what   = $offer['name'] . ($clubName ? " ($clubName)" : '');
            $amount = $this->formatEur($offer['price_cents']);
            $title  = "Euro-Kauf: $managerName – $what ($amount)";
            $msg    = "Code $code · $amount per PayPal an {$this->paypalMeName()} · Zahlung prüfen und im Shop bestätigen oder stornieren";

            foreach ($this->getAdminManagerIds() as $adminId) {
                $this->createNotification($adminId, $title, $msg, $managerId);
            }

            $adminEmails = $this->con->query(
                "SELECT m.email FROM manager m JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $body = "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"UTF-8\"></head>"
                . "<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:600px;margin:0 auto;\">"
                . "<h2 style=\"margin:0 0 12px;\">Neuer Euro-Kauf im Klebrigsten-Shop</h2>"
                . "<p><strong>" . htmlspecialchars($managerName) . "</strong> hat <strong>" . htmlspecialchars($what) . "</strong> "
                . "für <strong>$amount</strong> gekauft ({$offer['packs']} Pack(s) à {$offer['size']} Sticker).</p>"
                . "<p>Kauf-Code: <strong>$code</strong> · Zahlung per PayPal an " . htmlspecialchars($this->paypalMeName()) . "</p>"
                . "<p style=\"color:#64748b;\">Die Packs sind schon da; Karten daraus sind bis zur Bestätigung nicht tauschbar. "
                . "Bitte im Shop unter „Euro-Käufe“ bestätigen oder stornieren.</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";
            foreach ($adminEmails as $email) {
                mail($email, "Euro-Kauf $code: $managerName – $what ($amount) — die bestesten", $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('informAdminsOfStickerEur failed: ' . $e->getMessage());
        }
    }
}
