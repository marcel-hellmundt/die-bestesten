<?php

/**
 * "Die Klebrigsten" — Shop: Lukaten gegen Sticker-Packs. Bezahlt wird vom Lukaten-Konto des Managers
 * (LukatenAccountTrait) — ein Konto je Manager, unabhängig von Liga und Saison.
 */
trait StickerShopTrait
{
    /**
     * Die festen Pack-Arten des Shops (Lukaten einzeln, Euro als Kombinationen) — müssen zu PACK_KINDS in
     * shop.model.ts passen. guaranteed_new = so viele Karten garantiert neu, holo_min = mindestens so viele Holo,
     * club = nur Sticker eines beim Kauf gewählten Vereins.
     */
    protected function stickerPackKinds(): array
    {
        return [
            'normal'  => ['name' => 'Normales Pack', 'size' => 3, 'guaranteed_new' => 1, 'holo_min' => 0, 'club' => false],
            'big'     => ['name' => 'Big Pack',      'size' => 7, 'guaranteed_new' => 2, 'holo_min' => 0, 'club' => false],
            'club'    => ['name' => 'Vereins-Pack',  'size' => 5, 'guaranteed_new' => 5, 'holo_min' => 0, 'club' => true],
            'special' => ['name' => 'Special Pack',  'size' => 3, 'guaranteed_new' => 1, 'holo_min' => 1, 'club' => false],
        ];
    }

    /**
     * Lukaten-Angebote: je eine Pack-Art. Die Preise stehen in LukatenAccountTrait::lukatenAccountConfig()
     * (pack_prices); der Shop liefert sie mit aus (GET /sticker/shop → prices), shop.model.ts enthält sie nur als
     * Rückfall. Keys bleiben stabil (bestehende Käufe), l-small = Normales Pack.
     */
    protected function stickerShopOffers(): array
    {
        $kinds  = $this->stickerPackKinds();
        $prices = $this->lukatenAccountConfig()['pack_prices'];
        $offers = [];
        foreach (['l-small' => 'normal', 'l-big' => 'big', 'l-club' => 'club', 'l-special' => 'special'] as $key => $kind) {
            $offers[$key] = $kinds[$kind] + ['kind' => $kind, 'price' => $prices[$key]];
        }
        return $offers;
    }

    /** Ein Shop-Pack der Pack-Art $kind anlegen (ungeöffnet) — Rückgabe: Pack-ID. */
    private function insertStickerShopPack(string $managerId, string $seasonId, string $sourceKey, string $kind,
                                           ?string $leagueId, ?string $clubId, ?string $eurPurchaseId = null): string
    {
        $k = $this->stickerPackKinds()[$kind];
        $packId = $this->con->query("SELECT UUID()")->fetchColumn();
        $cols = ['id', 'manager_id', 'season_id', 'source', 'pack_kind', 'source_key', 'league_id', 'club_id', 'size', 'guaranteed_new', 'holo_min'];
        $vals = [$packId, $managerId, $seasonId, 'shop', $kind, $sourceKey, $leagueId, $k['club'] ? $clubId : null,
                 $k['size'], $k['guaranteed_new'], $k['holo_min'] ?: null];
        // eur_purchase_id nur bei Euro-Käufen — Lukaten-Käufe brauchen die Euro-Migration nicht
        if ($eurPurchaseId !== null) { $cols[] = 'eur_purchase_id'; $vals[] = $eurPurchaseId; }
        $this->con->prepare(
            'INSERT INTO sticker_pack (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
        )->execute($vals);
        return $packId;
    }

    /**
     * POST /sticker/shop/buy — Lukaten-Angebot kaufen: bezahlt vom Lukaten-Konto, das Pack landet ungeöffnet im
     * Album (sticker_pack source 'shop'). Pack und Buchung liegen beide in der globalen DB und entstehen in einer
     * Transaktion; ein Lock je Manager verhindert doppeltes Ausgeben bei parallelen Käufen und Tipps
     * (derselbe Lock wie in H2HPredictionTrait::submitH2HPrediction()). Mail + In-App-Benachrichtigung an alle Admins.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['pack_id', 'budget'].
     */
    public function buyStickerShopOffer(string $managerId, string $offerKey, ?string $clubId): array
    {
        $offer = $this->stickerShopOffers()[$offerKey] ?? null;
        if (!$offer) return ['error' => 422, 'message' => 'Unbekanntes Angebot'];
        if (!$this->isStickerEnabledForManager($managerId)) return ['error' => 409, 'message' => 'Du spielst in keiner Liga mit Sticker-Album'];
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null || !$this->stickerAlbumReady($seasonId)) return ['error' => 409, 'message' => 'Album der Saison existiert noch nicht'];
        if (!$this->lukatenLedgerReady()) return ['error' => 409, 'message' => 'Das Lukaten-Konto ist noch nicht eingerichtet'];

        $clubName = null;
        if ($offer['club']) {
            if (!$clubId) return ['error' => 422, 'message' => 'Bitte einen Verein wählen'];
            $clubName = $this->stickerShopClubName($seasonId, $clubId);
            if ($clubName === null) return ['error' => 422, 'message' => 'Verein ist nicht im Album'];
        } else {
            $clubId = null;
        }

        $lock = $this->lukatenLockName($managerId);
        $this->con->prepare("SELECT GET_LOCK(?, 5)")->execute([$lock]);
        try {
            $budget = $this->getLukatenBalance($managerId);
            if ($budget + 1e-9 < $offer['price']) {
                return ['error' => 422, 'message' => 'Nicht genug Lukaten (' . $this->formatLukaten($budget) . ' von ' . $offer['price'] . ')'];
            }

            $this->con->beginTransaction();
            try {
                $purchaseId = $this->con->query("SELECT UUID()")->fetchColumn();
                $packId = $this->insertStickerShopPack($managerId, $seasonId, "shop:{$offerKey}:{$purchaseId}", $offer['kind'], null, $clubId);
                // INSERT IGNORE meldet eine abgewiesene Buchung nicht als Fehler — ohne Buchung kein Pack
                if (!$this->bookLukaten($managerId, -$offer['price'], 'pack', "pack:$packId", $seasonId, $packId)) {
                    throw new \RuntimeException('Lukaten-Buchung wurde nicht angelegt');
                }
                $this->con->commit();
            } catch (\Throwable $e) {
                if ($this->con->inTransaction()) $this->con->rollBack();
                error_log('buyStickerShopOffer: ' . $e->getMessage());
                return ['error' => 409, 'message' => 'Shop-Packs sind noch nicht eingerichtet'];
            }
            $budgetAfter = round($budget - $offer['price'], 2);
        } finally {
            $this->con->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
        }

        $this->sendStickerShopAdminEmail($managerId, $offer, $clubName, $budgetAfter);
        $this->notifyAdminsOfStickerShop($managerId, $offer, $clubName, $budgetAfter);
        return ['pack_id' => $packId, 'budget' => $budgetAfter];
    }

    /** Name des Vereins, falls er im Album der Saison vorkommt (Vereins-Pack), sonst null. */
    private function stickerShopClubName(string $seasonId, string $clubId): ?string
    {
        $cq = $this->con->prepare(
            "SELECT c.name FROM sticker s JOIN club c ON c.id = s.club_id WHERE s.season_id = ? AND s.club_id = ? LIMIT 1"
        );
        $cq->execute([$seasonId, $clubId]);
        $name = $cq->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    private function formatLukaten(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }

    /** In-App-Benachrichtigung an alle Admins bei jedem Shop-Kauf (analog zur Mail), Absender = Käufer. */
    private function notifyAdminsOfStickerShop(string $managerId, array $offer, ?string $clubName, float $budgetAfter): void
    {
        try {
            $n = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
            $n->execute([$managerId]);
            $managerName = (string) $n->fetchColumn();
            $what    = $offer['name'] . ($clubName ? " ($clubName)" : '');
            $title   = "Shop-Kauf: $managerName – $what";
            $message = "{$offer['price']} Lukaten · {$offer['size']} Sticker · Guthaben danach: "
                . $this->formatLukaten($budgetAfter) . ' Lukaten';
            foreach ($this->getAdminManagerIds() as $adminId) {
                $this->createNotification($adminId, $title, $message, $managerId);
            }
        } catch (\Throwable $e) {
            error_log('notifyAdminsOfStickerShop failed: ' . $e->getMessage());
        }
    }

    /** Mail an alle Admins (mit E-Mail) bei jedem Shop-Kauf. */
    private function sendStickerShopAdminEmail(string $managerId, array $offer, ?string $clubName, float $budgetAfter): void
    {
        try {
            $adminEmails = $this->con->query(
                "SELECT m.email FROM manager m
                 JOIN manager_role mr ON mr.manager_id = m.id
                 WHERE mr.role = 'admin' AND m.email IS NOT NULL AND m.status = 'active'"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (empty($adminEmails)) return;

            $n = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
            $n->execute([$managerId]);
            $managerName = (string) $n->fetchColumn();
            $what = $offer['name'] . ($clubName ? ' (' . $clubName . ')' : '');

            $subject = "Shop-Kauf: $managerName – {$offer['name']} — die bestesten";
            $body    = "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"UTF-8\"></head>"
                . "<body style=\"font-family:sans-serif;color:#1e293b;background:#f8fafc;padding:24px;max-width:600px;margin:0 auto;\">"
                . "<h2 style=\"margin:0 0 12px;\">Neuer Kauf im Klebrigsten-Shop</h2>"
                . "<p><strong>" . htmlspecialchars($managerName) . "</strong> hat <strong>" . htmlspecialchars($what) . "</strong> "
                . "für <strong>{$offer['price']} Lukaten</strong> gekauft ({$offer['size']} Sticker).</p>"
                . "<p style=\"color:#64748b;\">Guthaben danach: " . $this->formatLukaten($budgetAfter) . " Lukaten</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendStickerShopAdminEmail failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /sticker/shop/lukaten — alle Lukaten-Käufe der aktiven Saison (Admin), neueste zuerst. Maßgeblich sind die
     * Buchungen: die Pack-Käufe im Kontobuch (lukaten_transaction, source 'pack') und, für Käufe vor der Umstellung
     * aufs Lukaten-Konto, sticker_shop_purchase in den Liga-DBs (league_name = Liga, aus der damals bezahlt wurde).
     * Die Details (Pack-Art, Verein, geöffnet) kommen aus dem zugehörigen sticker_pack. Shop-Packs
     * (source_key shop:l-…) ohne Buchung erscheinen mit booked=false und price=null und zählen nicht zur Summe
     * (Hinweis auf eine Unstimmigkeit). total = Summe der Buchungen.
     */
    public function getStickerLukatenPurchases(): array
    {
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null) return ['purchases' => [], 'total' => 0];

        // Shop-Packs der Saison (global) — Details je Pack
        try {
            $q = $this->con->prepare(
                "SELECT sp.id AS pack_id, sp.manager_id, sp.source_key, sp.pack_kind, sp.club_id, c.name AS club_name,
                        sp.created_at, sp.opened_at
                 FROM sticker_pack sp
                 LEFT JOIN club c ON c.id = sp.club_id
                 WHERE sp.season_id = ? AND sp.source = 'shop' AND sp.source_key LIKE 'shop:l-%'"
            );
            $q->execute([$seasonId]);
            $packs = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) $packs[$p['pack_id']] = $p;
        } catch (\Throwable $e) {
            $packs = []; // Shop-Migration fehlt
        }

        // Buchungen: Kontobuch …
        $bookings = [];
        try {
            $lq = $this->con->prepare(
                "SELECT manager_id, -amount AS price, pack_id, created_at FROM lukaten_transaction WHERE source = 'pack' AND season_id = ?"
            );
            $lq->execute([$seasonId]);
            foreach ($lq->fetchAll(PDO::FETCH_ASSOC) as $b) $bookings[] = $b + ['offer_key' => null, 'league_name' => null];
        } catch (\Throwable $e) {
            // Kontobuch fehlt (Migration)
        }
        // … und alte Käufe aus allen Liga-DBs (fehlt die Tabelle in einer Liga: überspringen)
        foreach ($this->con->query("SELECT id, name, db_name FROM league")->fetchAll(PDO::FETCH_ASSOC) as $league) {
            if (!$league['db_name']) continue;
            try {
                $bq = $this->lukatenLeagueConnection($league)->prepare(
                    "SELECT manager_id, offer_key, price, pack_id, created_at FROM sticker_shop_purchase WHERE season_id = ?"
                );
                $bq->execute([$seasonId]);
                foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) $bookings[] = $b + ['league_name' => $league['name']];
            } catch (\Throwable $e) {
                // keine Shop-Tabelle in dieser Liga
            }
        }

        $offers = $this->stickerShopOffers();
        $kinds  = $this->stickerPackKinds();
        $entry = function (?array $pack, ?array $booking) use ($offers, $kinds): array {
            $offerKey = $booking['offer_key'] ?? (explode(':', $pack['source_key'] ?? '')[1] ?? '');
            $kind = $pack['pack_kind'] ?? ($offers[$offerKey]['kind'] ?? null);
            return [
                'pack_id' => $pack['pack_id'] ?? ($booking['pack_id'] ?? null),
                'manager_id' => $booking['manager_id'] ?? $pack['manager_id'],
                'offer_key' => $offerKey, 'pack_kind' => $kind,
                'offer_name' => $kinds[$kind]['name'] ?? ($offers[$offerKey]['name'] ?? $offerKey),
                'club_id' => $pack['club_id'] ?? null, 'club_name' => $pack['club_name'] ?? null,
                'price' => $booking ? (int) $booking['price'] : null,
                'booked' => $booking !== null,
                'league_name' => $booking['league_name'] ?? null,
                'created_at' => $booking['created_at'] ?? $pack['created_at'],
                'opened' => $pack !== null && $pack['opened_at'] !== null,
            ];
        };

        $purchases = [];
        $total = 0;
        foreach ($bookings as $b) {
            $pack = $b['pack_id'] !== null ? ($packs[$b['pack_id']] ?? null) : null;
            if ($pack) unset($packs[$b['pack_id']]);
            $purchases[] = $entry($pack, $b);
            $total += (int) $b['price'];
        }
        foreach ($packs as $pack) $purchases[] = $entry($pack, null); // Pack ohne Buchung

        // Managernamen nachladen, neueste zuerst
        $ids = array_values(array_unique(array_column($purchases, 'manager_id')));
        $names = [];
        if ($ids) {
            $nq = $this->con->prepare('SELECT id, manager_name FROM manager WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $nq->execute($ids);
            $names = $nq->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        foreach ($purchases as &$p) $p['manager_name'] = $names[$p['manager_id']] ?? '?';
        unset($p);
        usort($purchases, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return ['purchases' => $purchases, 'total' => $total];
    }

    /**
     * GET /sticker/shop — Lukaten-Guthaben (Kontostand, null ohne Sticker-Album oder ohne Kontobuch), Preise je
     * Lukaten-Angebot und die eigenen offenen Euro-Zahlungen für Packs. lukaten_available = ob Lukaten-Käufe
     * möglich sind.
     */
    public function getStickerShop(string $managerId): array
    {
        $eur    = $this->getStickerEurState($managerId, $this->getActiveSeasonId());
        $budget = null;
        if ($this->isStickerEnabledForManager($managerId) && $this->lukatenLedgerReady()) {
            try {
                $budget = $this->getLukatenBalance($managerId);
            } catch (\Throwable $e) {
                // Kontostand gerade nicht ermittelbar → keine Lukaten-Käufe, die Euro-Angebote bleiben
                error_log('getStickerShop balance: ' . $e->getMessage());
            }
        }
        // Lukaten-Käufe (Bündel) werden auf /lukaten bezahlt, nicht hier
        $bundles = $this->lukatenEurBundles();
        $eur['pending'] = array_values(array_filter($eur['pending'], fn($p) => !isset($bundles[$p['offer_key']])));

        return [
            'budget'            => $budget,
            'lukaten_available' => $budget !== null,
            'prices'            => array_map(fn($o) => $o['price'], $this->stickerShopOffers()),
            'eur'               => $eur,
        ];
    }
}
