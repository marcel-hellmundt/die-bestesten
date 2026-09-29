<?php

/**
 * "Die Klebrigsten" — Shop: Lukaten (Wettwährung aus dem Bestico) gegen Sticker-Packs.
 * Lukaten gibt es je Liga, das Album aber nur einmal je Manager — bezahlt wird deshalb immer aus der
 * Hauptliga des Managers: seiner obersten Liga mit Sticker-Album (Division mit dem niedrigsten level,
 * bei Gleichstand die zuerst beigetretene), unabhängig davon, in welcher Liga er gerade eingeloggt ist.
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
     * Lukaten-Angebote: je eine Pack-Art — maßgeblich sind die Preise hier (shop.model.ts muss passen).
     * Keys bleiben stabil (bestehende Käufe), l-small = Normales Pack.
     */
    protected function stickerShopOffers(): array
    {
        $kinds = $this->stickerPackKinds();
        $offers = ['l-small' => ['normal', 15], 'l-big' => ['big', 30], 'l-club' => ['club', 40], 'l-special' => ['special', 45]];
        return array_map(fn($o) => $kinds[$o[0]] + ['kind' => $o[0], 'price' => $o[1]], $offers);
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
     * POST /sticker/shop/buy — Lukaten-Angebot kaufen: bezahlt aus der Hauptliga (sticker_shop_purchase in deren
     * Liga-DB → mindert das Lukaten-Budget dort), Pack landet ungeöffnet im Album (sticker_pack source 'shop').
     * Named Lock je Manager+Liga gegen doppeltes Ausgeben bei parallelen Käufen. Mail + In-App-Benachrichtigung an alle Admins.
     * Rückgabe ['error' => HTTP-Code, 'message'] oder ['pack_id', 'budget'].
     */
    public function buyStickerShopOffer(string $managerId, string $offerKey, ?string $clubId): array
    {
        $offer = $this->stickerShopOffers()[$offerKey] ?? null;
        if (!$offer) return ['error' => 422, 'message' => 'Unbekanntes Angebot'];

        $league   = $this->getStickerShopLeague($managerId);
        $seasonId = $this->getActiveSeasonId();
        if (!$league) return ['error' => 409, 'message' => 'Du spielst in keiner Liga mit Sticker-Album'];
        if ($seasonId === null || !$this->stickerAlbumReady($seasonId)) return ['error' => 409, 'message' => 'Album der Saison existiert noch nicht'];

        $clubName = null;
        if ($offer['club']) {
            if (!$clubId) return ['error' => 422, 'message' => 'Bitte einen Verein wählen'];
            $clubName = $this->stickerShopClubName($seasonId, $clubId);
            if ($clubName === null) return ['error' => 422, 'message' => 'Verein ist nicht im Album'];
        } else {
            $clubId = null;
        }

        $db   = $this->stickerShopConnection($league);
        // MySQL-Lock-Namen max. 64 Zeichen → Liga+Manager gehasht (8 + 32 Zeichen)
        $lock = 'lukaten:' . md5($league['id'] . ':' . $managerId);
        $db->prepare("SELECT GET_LOCK(?, 5)")->execute([$lock]);
        try {
            try {
                $budget = $this->getManagerLukatenBudget($managerId, $seasonId, null, false, $db);
            } catch (\Throwable $e) {
                return ['error' => 409, 'message' => 'Shop ist in deiner Liga noch nicht eingerichtet'];
            }
            if ($budget + 1e-9 < $offer['price']) {
                return ['error' => 422, 'message' => 'Nicht genug Lukaten (' . $this->formatLukaten($budget) . ' von ' . $offer['price'] . ')'];
            }

            $purchaseId = $db->query("SELECT UUID()")->fetchColumn();
            try {
                $db->prepare(
                    "INSERT INTO sticker_shop_purchase (id, manager_id, season_id, offer_key, price) VALUES (?, ?, ?, ?, ?)"
                )->execute([$purchaseId, $managerId, $seasonId, $offerKey, $offer['price']]);
            } catch (\Throwable $e) {
                return ['error' => 409, 'message' => 'Shop ist in deiner Liga noch nicht eingerichtet'];
            }

            // Pack anlegen (globale DB) — schlägt das fehl, wird der Kauf zurückgenommen (zwei DBs, keine gemeinsame Transaktion)
            try {
                $packId = $this->insertStickerShopPack($managerId, $seasonId, "shop:{$offerKey}:{$purchaseId}", $offer['kind'], $league['id'], $clubId);
                $db->prepare("UPDATE sticker_shop_purchase SET pack_id = ? WHERE id = ?")->execute([$packId, $purchaseId]);
            } catch (\Throwable $e) {
                $db->prepare("DELETE FROM sticker_shop_purchase WHERE id = ?")->execute([$purchaseId]);
                error_log('buyStickerShopOffer: ' . $e->getMessage());
                return ['error' => 409, 'message' => 'Shop-Packs sind noch nicht eingerichtet'];
            }
            $budgetAfter = $budget - $offer['price'];
        } finally {
            $db->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
        }

        $this->sendStickerShopAdminEmail($managerId, $offer, $clubName, $league['name'], $budgetAfter);
        $this->notifyAdminsOfStickerShop($managerId, $offer, $clubName, $league['name'], $budgetAfter);
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
    private function notifyAdminsOfStickerShop(string $managerId, array $offer, ?string $clubName, string $leagueName, float $budgetAfter): void
    {
        try {
            $n = $this->con->prepare("SELECT manager_name FROM manager WHERE id = ?");
            $n->execute([$managerId]);
            $managerName = (string) $n->fetchColumn();
            $what    = $offer['name'] . ($clubName ? " ($clubName)" : '');
            $title   = "Shop-Kauf: $managerName – $what";
            $message = "{$offer['price']} Lukaten · {$offer['size']} Sticker · bezahlt aus $leagueName · Guthaben danach: "
                . $this->formatLukaten($budgetAfter) . ' Lukaten';
            foreach ($this->getAdminManagerIds() as $adminId) {
                $this->createNotification($adminId, $title, $message, $managerId);
            }
        } catch (\Throwable $e) {
            error_log('notifyAdminsOfStickerShop failed: ' . $e->getMessage());
        }
    }

    /** Mail an alle Admins (mit E-Mail) bei jedem Shop-Kauf. */
    private function sendStickerShopAdminEmail(string $managerId, array $offer, ?string $clubName, string $leagueName, float $budgetAfter): void
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
                . "<p style=\"color:#64748b;\">Bezahlt aus der Liga " . htmlspecialchars($leagueName)
                . " · Guthaben danach: " . $this->formatLukaten($budgetAfter) . " Lukaten</p>"
                . "</body></html>";
            $headers = "From: noreply@die-bestesten.de\r\nContent-Type: text/html; charset=UTF-8";

            foreach ($adminEmails as $email) {
                mail($email, $subject, $body, $headers);
            }
        } catch (\Throwable $e) {
            error_log('sendStickerShopAdminEmail failed: ' . $e->getMessage());
        }
    }

    /** Hauptliga des Managers {id, name, db_name} oder null (in keiner aktiven Liga mit Sticker-Album). */
    public function getStickerShopLeague(string $managerId): ?array
    {
        try {
            $q = $this->con->prepare(
                "SELECT l.id, l.name, l.db_name
                 FROM manager_league ml
                 JOIN league l ON l.id = ml.league_id
                 LEFT JOIN division d ON d.id = l.division_id
                 WHERE ml.manager_id = ? AND ml.status = 'active' AND l.sticker_enabled = 1
                 ORDER BY d.level IS NULL, d.level ASC, ml.joined_at ASC, l.name ASC
                 LIMIT 1"
            );
            $q->execute([$managerId]);
            return $q->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return null; // Migration (sticker_enabled) fehlt
        }
    }

    /** Verbindung zur Liga-DB der Hauptliga — die bestehende, falls der Manager gerade dort eingeloggt ist. */
    private function stickerShopConnection(array $league): PDO
    {
        if ($this->con_league && ($GLOBALS['auth_league_id'] ?? null) === $league['id']) {
            return $this->con_league;
        }
        return $this->createConnection($_ENV['DB_HOST'], $league['db_name'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);
    }

    /**
     * GET /sticker/shop/lukaten — alle Lukaten-Käufe der aktiven Saison (Admin), neueste zuerst. Maßgeblich sind die
     * Buchungen sticker_shop_purchase in den Liga-DBs (dieselben Zeilen, die das Lukaten-Budget mindern und in der
     * Schatzkammer als "Shop" zählen) — die Details (Pack-Art, Verein, geöffnet) kommen aus dem zugehörigen
     * sticker_pack. Shop-Packs (source_key shop:l-…) ohne Buchung erscheinen mit booked=false und price=null und
     * zählen nicht zur Summe (Hinweis auf eine Unstimmigkeit). total = Summe der Buchungen.
     */
    public function getStickerLukatenPurchases(): array
    {
        $seasonId = $this->getActiveSeasonId();
        if ($seasonId === null) return ['purchases' => [], 'total' => 0];

        // Shop-Packs der Saison (global) — Details je Pack
        try {
            $q = $this->con->prepare(
                "SELECT sp.id AS pack_id, sp.manager_id, sp.source_key, sp.pack_kind, sp.club_id, c.name AS club_name,
                        l.name AS league_name, sp.created_at, sp.opened_at
                 FROM sticker_pack sp
                 LEFT JOIN club c ON c.id = sp.club_id
                 LEFT JOIN league l ON l.id = sp.league_id
                 WHERE sp.season_id = ? AND sp.source = 'shop' AND sp.source_key LIKE 'shop:l-%'"
            );
            $q->execute([$seasonId]);
            $packs = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) $packs[$p['pack_id']] = $p;
        } catch (\Throwable $e) {
            $packs = []; // Shop-Migration fehlt
        }

        // Buchungen aus allen Liga-DBs (fehlt die Tabelle in einer Liga: überspringen)
        $bookings = [];
        foreach ($this->con->query("SELECT id, name, db_name FROM league")->fetchAll(PDO::FETCH_ASSOC) as $league) {
            if (!$league['db_name']) continue;
            try {
                $db = $this->stickerShopConnection($league);
                $bq = $db->prepare(
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
                'league_name' => $booking['league_name'] ?? ($pack['league_name'] ?? null),
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

    /** GET /sticker/shop — Hauptliga + Lukaten-Guthaben dort (aktive Saison) + eigene Euro-Käufe. */
    public function getStickerShop(string $managerId): array
    {
        $league   = $this->getStickerShopLeague($managerId);
        $seasonId = $this->getActiveSeasonId();
        $eur      = $this->getStickerEurState($managerId, $seasonId);
        if (!$league || !$seasonId) {
            return ['league' => $league ? ['id' => $league['id'], 'name' => $league['name']] : null, 'budget' => null, 'eur' => $eur];
        }
        $budget = $this->getManagerLukatenBudget($managerId, $seasonId, null, false, $this->stickerShopConnection($league));
        return ['league' => ['id' => $league['id'], 'name' => $league['name']], 'budget' => $budget, 'eur' => $eur];
    }
}
