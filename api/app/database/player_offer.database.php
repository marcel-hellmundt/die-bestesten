<?php

/**
 * Direktangebote zwischen Managern ("Hinterzimmerdeals"): Gebot auf einen Spieler, der aktuell im
 * Team eines anderen Managers ist. Siehe league_schema.sql (player_offer) für das Datenmodell.
 *
 * Kernregeln:
 *  - Anlegen jederzeit; Annehmen/Ablehnen nur innerhalb einer offenen Transferphase (Verkäufer).
 *  - Gültig bis Ende des Fensters expires_window_id (laufende Phase, sonst nächste) — Ablauf wird
 *    immer live gegen transferwindow.end_date bewertet (kein Cron), siehe expireStalePlayerOffers().
 *  - Offene Angebote reservieren das Budget des Bieters (getReservedBudget()).
 *  - Annahme = ein atomarer Vollzug in einer con_league-Transaktion unter Named-Locks (Spieler +
 *    beide Teams); alle Vorbedingungen werden unter dem Lock erneut geprüft.
 */
trait PlayerOfferTrait
{
    private const MAX_OPEN_PLAYER_OFFERS = 10;

    private ?bool $playerOfferTableExists = null;

    /**
     * Die Bestandsfunktionen (Gebote, Budget, Transferfenster) rufen die Reservierungs-Helfer unten
     * auf. Fehlt die Migration (database/migrate_player_offer.sql) auf einer Liga-DB noch, sollen sie
     * dort weiter funktionieren statt mit "Table doesn't exist" abzustürzen — die Direktangebote selbst
     * bleiben bis zur Migration natürlich nicht nutzbar.
     */
    public function hasPlayerOfferTable(): bool
    {
        if ($this->playerOfferTableExists === null) {
            $this->playerOfferTableExists = (bool) $this->con_league->query("SHOW TABLES LIKE 'player_offer'")->fetchColumn();
        }
        return $this->playerOfferTableExists;
    }

    // ─── Helfer ────────────────────────────────────────────────────────────────────

    /**
     * Setzt offene Direktangebote, deren Ablauf-Fenster beendet ist (oder nicht mehr existiert), auf
     * 'expired'. Es gibt keinen Cron — Korrektheit hängt daher nie an diesem Nachtragen, sondern
     * daran, dass jede Lese-/Prüfpfad-Funktion sie vorher aufruft, bevor sie Reservierungen zählt.
     */
    public function expireStalePlayerOffers(): void
    {
        if (!$this->hasPlayerOfferTable()) return;

        $wids = $this->con_league->query(
            "SELECT DISTINCT expires_window_id FROM player_offer WHERE status = 'pending'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (empty($wids)) return;

        $ph = implode(',', array_fill(0, count($wids), '?'));
        $wq = $this->con->prepare("SELECT id FROM transferwindow WHERE id IN ($ph) AND end_date > NOW()");
        $wq->execute($wids);
        $alive = $wq->fetchAll(PDO::FETCH_COLUMN);

        $stale = array_values(array_diff($wids, $alive));
        if (empty($stale)) return;

        $sph = implode(',', array_fill(0, count($stale), '?'));
        $this->con_league->prepare(
            "UPDATE player_offer SET status = 'expired', responded_at = NOW()
             WHERE status = 'pending' AND expires_window_id IN ($sph)"
        )->execute($stale);
    }

    public function getTeamBudgetValue(string $teamId): int
    {
        $q = $this->con_league->prepare("SELECT COALESCE(SUM(amount), 0) FROM transaction WHERE team_id = :tid");
        $q->execute([':tid' => $teamId]);
        return (int) $q->fetchColumn();
    }

    /**
     * Vom Budget eines Teams reservierter Betrag: offene Gebote auf freie Spieler (offer) plus offene
     * Direktangebote (player_offer), die dieses Team als Bieter abgegeben hat. $excludeOfferId /
     * $excludePlayerOfferId nehmen ein bestimmtes Angebot aus (Bearbeiten/Annehmen desselben).
     */
    public function getReservedBudget(string $teamId, ?string $excludeOfferId = null, ?string $excludePlayerOfferId = null): int
    {
        $this->expireStalePlayerOffers();

        $q = $this->con_league->prepare(
            "SELECT COALESCE(SUM(offer_value), 0) FROM offer
             WHERE team_id = :tid AND status = 'pending' AND (:ex IS NULL OR id != :ex2)"
        );
        $q->execute([':tid' => $teamId, ':ex' => $excludeOfferId, ':ex2' => $excludeOfferId]);
        $sum = (int) $q->fetchColumn();

        if (!$this->hasPlayerOfferTable()) return $sum;

        $q = $this->con_league->prepare(
            "SELECT COALESCE(SUM(offer_value), 0) FROM player_offer
             WHERE buyer_team_id = :tid AND status = 'pending' AND (:ex IS NULL OR id != :ex2)"
        );
        $q->execute([':tid' => $teamId, ':ex' => $excludePlayerOfferId, ':ex2' => $excludePlayerOfferId]);
        return $sum + (int) $q->fetchColumn();
    }

    /** Spieler-IDs der offenen Direktangebote eines Bieters (zählen gegen das Positionslimit). */
    public function getPendingPlayerOfferPlayerIds(string $buyerTeamId): array
    {
        if (!$this->hasPlayerOfferTable()) return [];
        $this->expireStalePlayerOffers();
        $q = $this->con_league->prepare(
            "SELECT player_id FROM player_offer WHERE buyer_team_id = :tid AND status = 'pending'"
        );
        $q->execute([':tid' => $buyerTeamId]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Marktwert nach der Verkaufsformel (identisch zu sellPlayer(): Grundpreis der aktuellen
     * Division + Saisonpunkte * points_bonus, gerundet) — der Verkäufer erhält genau diesen Betrag,
     * wenn er an den Markt verkauft; als Mindestpreis für Direktangebote daher die maßgebliche Größe.
     * Gibt null zurück, wenn der Spieler in dieser Saison keine player_in_season-Zeile hat.
     */
    public function getPlayerMarketValueInfo(string $playerId, string $seasonId): ?array
    {
        $pq = $this->con->prepare(
            "SELECT COALESCE(pis.price, 0) AS price, pis.position, p.displayname
             FROM player_in_season pis
             JOIN player p ON p.id = pis.player_id
             WHERE pis.player_id = :pid AND pis.season_id = :sid
               AND pis.division_id = COALESCE(
                     (SELECT cis.division_id
                      FROM player_in_club pic
                      JOIN club_in_season cis ON cis.club_id = pic.club_id AND cis.season_id = pis.season_id
                      WHERE pic.player_id = pis.player_id AND pic.to_date IS NULL
                      LIMIT 1),
                     pis.division_id)
             LIMIT 1"
        );
        $pq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $row = $pq->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $ptq = $this->con->prepare(
            "SELECT COALESCE(SUM(pr.points), 0)
             FROM player_rating pr
             JOIN matchday m ON m.id = pr.matchday_id
             WHERE pr.player_id = :pid AND m.season_id = :sid"
        );
        $ptq->execute([':pid' => $playerId, ':sid' => $seasonId]);
        $points = (int) $ptq->fetchColumn();

        return [
            'market_value' => (int) round((float) $row['price'] + $points * $this->getDivisionConfig()['points_bonus']),
            'position'     => $row['position'],
            'displayname'  => $row['displayname'],
        ];
    }

    /** Anzahl der übergebenen Spieler auf einer Position (Zeile der aktuellen Division je Spieler). */
    private function countPlayersAtPosition(array $playerIds, string $seasonId, string $position): int
    {
        $playerIds = array_values(array_unique($playerIds));
        if (empty($playerIds)) return 0;

        $ph = implode(',', array_fill(0, count($playerIds), '?'));
        $cq = $this->con->prepare(
            "SELECT COUNT(*) FROM player_in_season pis
             LEFT JOIN player_in_club pic_cur ON pic_cur.player_id = pis.player_id AND pic_cur.to_date IS NULL
             LEFT JOIN club_in_season cis_cur ON cis_cur.club_id = pic_cur.club_id AND cis_cur.season_id = pis.season_id
             WHERE pis.player_id IN ($ph) AND pis.season_id = ? AND pis.position = ?
               AND (cis_cur.division_id IS NULL OR pis.division_id = cis_cur.division_id OR NOT EXISTS (SELECT 1 FROM player_in_season pis_chk WHERE pis_chk.player_id = pis.player_id AND pis_chk.season_id = pis.season_id AND pis_chk.division_id = cis_cur.division_id))"
        );
        $cq->execute(array_merge($playerIds, [$seasonId, $position]));
        return (int) $cq->fetchColumn();
    }

    /**
     * Belegte Plätze eines Teams auf einer Position: aktueller Kader + offene Gebote auf freie Spieler
     * + offene Direktangebote (jeweils als Bieter) — gleiche Zählweise wie submitOffer().
     */
    private function countTeamPositionSlots(string $teamId, string $seasonId, string $position): int
    {
        $sq = $this->con_league->prepare(
            "SELECT player_id FROM player_in_team WHERE team_id = :tid AND to_matchday_id IS NULL"
        );
        $sq->execute([':tid' => $teamId]);
        $squadIds = $sq->fetchAll(PDO::FETCH_COLUMN);

        $bq = $this->con_league->prepare("SELECT player_id FROM offer WHERE team_id = :tid AND status = 'pending'");
        $bq->execute([':tid' => $teamId]);
        $pendingIds = array_merge($bq->fetchAll(PDO::FETCH_COLUMN), $this->getPendingPlayerOfferPlayerIds($teamId));

        return $this->countPlayersAtPosition($squadIds, $seasonId, $position)
             + $this->countPlayersAtPosition($pendingIds, $seasonId, $position);
    }

    /** Team der aktiven Saison, das den Spieler aktuell hält (oder null). */
    public function getActiveOwnerTeamId(string $playerId, string $seasonId): ?string
    {
        $q = $this->con_league->prepare(
            "SELECT pit.team_id FROM player_in_team pit
             JOIN team t ON t.id = pit.team_id
             WHERE pit.player_id = :pid AND pit.to_matchday_id IS NULL AND t.season_id = :sid
             LIMIT 1"
        );
        $q->execute([':pid' => $playerId, ':sid' => $seasonId]);
        return $q->fetchColumn() ?: null;
    }

    /**
     * Transferfenster der Saison (Division der Liga): $onlyOpen=true → das gerade offene, sonst das
     * erste Fenster, das noch nicht beendet ist (also das laufende, andernfalls das nächste). Alles per
     * SQL-NOW(), damit Fenster-Checks und Ablauf dieselbe Uhr benutzen.
     */
    private function findTransferwindow(string $seasonId, bool $onlyOpen): ?array
    {
        $divisionId = $this->getLeagueDivisionId();
        $where = $onlyOpen
            ? "tw.start_date <= NOW() AND tw.end_date > NOW()"
            : "tw.end_date > NOW()";

        if ($divisionId !== null) {
            $q = $this->con->prepare(
                "SELECT tw.id, tw.matchday_id, tw.start_date, tw.end_date, (tw.start_date <= NOW()) AS is_open
                 FROM transferwindow tw JOIN matchday m ON m.id = tw.matchday_id
                 WHERE m.season_id = :sid AND m.division_id = :did AND $where
                 ORDER BY tw.start_date ASC LIMIT 1"
            );
            $q->execute([':sid' => $seasonId, ':did' => $divisionId]);
        } else {
            $q = $this->con->prepare(
                "SELECT tw.id, tw.matchday_id, tw.start_date, tw.end_date, (tw.start_date <= NOW()) AS is_open
                 FROM transferwindow tw
                 JOIN matchday m ON m.id = tw.matchday_id
                 JOIN division d ON d.id = m.division_id
                 WHERE m.season_id = :sid AND d.level = 1 AND LOWER(d.country_id) = 'de' AND $where
                 ORDER BY tw.start_date ASC LIMIT 1"
            );
            $q->execute([':sid' => $seasonId]);
        }
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['is_open'] = (bool) $row['is_open'];
        return $row;
    }

    private function acquireDealLocks(array $names): bool
    {
        sort($names); // feste Reihenfolge → keine Deadlocks zwischen parallelen Vollzügen
        $acquired = [];
        foreach ($names as $name) {
            $st = $this->con_league->prepare('SELECT GET_LOCK(?, 10)');
            $st->execute([$name]);
            if (!$st->fetchColumn()) {
                $this->releaseDealLocks($acquired);
                return false;
            }
            $acquired[] = $name;
        }
        return true;
    }

    private function releaseDealLocks(array $names): void
    {
        foreach ($names as $name) {
            $this->con_league->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    private function dealError(int $http, string $message): array
    {
        return ['error' => true, 'http' => $http, 'message' => $message];
    }

    /** In-App-Nachricht an den Manager eines Teams (respektiert die Einstellung 'direct_offer'). */
    private function notifyTeamManager(string $teamId, string $title, string $message): void
    {
        try {
            $q = $this->con_league->prepare("SELECT manager_id FROM team WHERE id = :id LIMIT 1");
            $q->execute([':id' => $teamId]);
            $managerId = $q->fetchColumn();
            if (!$managerId || !$this->isNotificationEnabled($managerId, 'direct_offer')) return;
            $this->createNotification($managerId, $title, $message, null);
        } catch (\Throwable $e) {
            error_log('notifyTeamManager: ' . $e->getMessage());
        }
    }

    private function getTeamName(string $teamId): string
    {
        $q = $this->con_league->prepare("SELECT team_name FROM team WHERE id = ? LIMIT 1");
        $q->execute([$teamId]);
        return $q->fetchColumn() ?: 'Unbekanntes Team';
    }

    /**
     * Macht alle offenen Direktangebote auf einen Spieler hinfällig (Spieler wurde anderweitig
     * vergeben oder an den Markt verkauft). Gibt die betroffenen Angebote zurück (für Nachrichten).
     */
    public function voidPendingPlayerOffers(string $playerId, ?string $exceptOfferId = null): array
    {
        if (!$this->hasPlayerOfferTable()) return [];

        $q = $this->con_league->prepare(
            "SELECT id, buyer_team_id FROM player_offer
             WHERE player_id = :pid AND status = 'pending' AND (:ex IS NULL OR id != :ex2)"
        );
        $q->execute([':pid' => $playerId, ':ex' => $exceptOfferId, ':ex2' => $exceptOfferId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $this->con_league->prepare(
            "UPDATE player_offer SET status = 'void', responded_at = NOW() WHERE status = 'pending' AND id IN ($ph)"
        )->execute($ids);
        return $rows;
    }

    // ─── Anlegen ───────────────────────────────────────────────────────────────────

    public function createPlayerOffer(string $buyerTeamId, string $playerId, int $offerValue): array
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) return $this->dealError(422, 'Keine aktive Saison');
        if ($offerValue <= 0) return $this->dealError(422, 'Ungültiger Betrag');

        // Budget-/Slot-Prüfung und Insert müssen pro Bieter serialisiert laufen, sonst könnten zwei
        // parallele Angebote dieselbe Budgetreserve doppelt nutzen.
        $lockNames = ['deal_team_' . $buyerTeamId];
        if (!$this->acquireDealLocks($lockNames)) return $this->dealError(409, 'Bitte gleich nochmal versuchen');

        try {
            $this->expireStalePlayerOffers();

            $tq = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
            $tq->execute([':id' => $buyerTeamId]);
            if ($tq->fetchColumn() !== $seasonId) return $this->dealError(422, 'Dein Team gehört nicht zur aktiven Saison');

            $sellerTeamId = $this->getActiveOwnerTeamId($playerId, $seasonId);
            if ($sellerTeamId === null) return $this->dealError(409, 'Spieler ist in keinem Team (nutze ein normales Gebot)');
            if ($sellerTeamId === $buyerTeamId) return $this->dealError(422, 'Der Spieler gehört bereits zu deinem Team');

            $cq = $this->con_league->prepare(
                "SELECT COUNT(*) FROM player_offer WHERE buyer_team_id = :tid AND status = 'pending'"
            );
            $cq->execute([':tid' => $buyerTeamId]);
            if ((int) $cq->fetchColumn() >= self::MAX_OPEN_PLAYER_OFFERS) {
                return $this->dealError(409, 'Zu viele offene Angebote (max. ' . self::MAX_OPEN_PLAYER_OFFERS . ')');
            }

            $window = $this->findTransferwindow($seasonId, false);
            if (!$window) return $this->dealError(422, 'Keine offene oder kommende Transferphase geplant');

            $info = $this->getPlayerMarketValueInfo($playerId, $seasonId);
            if (!$info) return $this->dealError(422, 'Spieler hat in dieser Saison keinen Marktwert');
            if ($offerValue < $info['market_value']) return $this->dealError(422, 'Angebot liegt unter dem Marktwert');

            $position = $info['position'] ?? '';
            if (isset(self::SQUAD_MAX[$position])
                && $this->countTeamPositionSlots($buyerTeamId, $seasonId, $position) >= self::SQUAD_MAX[$position]) {
                return $this->dealError(409, 'Positionslimit erreicht');
            }

            $available = $this->getTeamBudgetValue($buyerTeamId) - $this->getReservedBudget($buyerTeamId);
            if ($offerValue > $available) return $this->dealError(422, 'Nicht genug verfügbares Budget');

            $id = $this->con_league->query("SELECT UUID()")->fetchColumn();
            try {
                $this->con_league->prepare(
                    "INSERT INTO player_offer (id, player_id, buyer_team_id, seller_team_id, offer_value, price_snapshot, status, expires_window_id)
                     VALUES (:id, :pid, :buyer, :seller, :val, :snap, 'pending', :wid)"
                )->execute([
                    ':id' => $id, ':pid' => $playerId, ':buyer' => $buyerTeamId, ':seller' => $sellerTeamId,
                    ':val' => $offerValue, ':snap' => $info['market_value'], ':wid' => $window['id'],
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') return $this->dealError(409, 'Für diesen Spieler hast du bereits ein offenes Angebot');
                throw $e;
            }
        } finally {
            $this->releaseDealLocks($lockNames);
        }

        $buyerName = $this->getTeamName($buyerTeamId);
        $this->notifyTeamManager(
            $sellerTeamId,
            'Neues Angebot für einen deiner Spieler',
            "$buyerName bietet " . number_format($offerValue, 0, ',', '.') . " € für {$info['displayname']}. "
            . 'Du findest das Angebot unter Markt → Gebote und kannst es in einer Transferphase annehmen oder ablehnen.'
        );

        return ['id' => $id];
    }

    // ─── Lesen ─────────────────────────────────────────────────────────────────────

    /** Spieler-Kartendaten (Anzeigename, Position, Foto, Verein) für eine Liste von Spielern. */
    private function getPlayerCardInfoMap(array $playerIds, string $seasonId): array
    {
        $playerIds = array_values(array_unique($playerIds));
        if (empty($playerIds)) return [];

        $ph = implode(',', array_fill(0, count($playerIds), '?'));
        $pq = $this->con->prepare(
            "SELECT p.id, p.displayname, pis.photo_uploaded, pis.position,
                    pic.club_id, c.logo_uploaded AS club_logo_uploaded
             FROM player p
             LEFT JOIN player_in_club pic ON pic.player_id = p.id AND pic.to_date IS NULL
             LEFT JOIN club_in_season cis_cur ON cis_cur.club_id = pic.club_id AND cis_cur.season_id = ?
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ?
                 AND (cis_cur.division_id IS NULL OR pis.division_id = cis_cur.division_id OR NOT EXISTS (SELECT 1 FROM player_in_season pis_chk WHERE pis_chk.player_id = pis.player_id AND pis_chk.season_id = pis.season_id AND pis_chk.division_id = cis_cur.division_id))
             LEFT JOIN club c ON c.id = pic.club_id
             WHERE p.id IN ($ph)"
        );
        $pq->execute(array_merge([$seasonId, $seasonId], $playerIds));
        $map = [];
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $map[$p['id']] = [
                'displayname'        => $p['displayname'],
                'position'           => $p['position'],
                'photo_uploaded'     => (bool) $p['photo_uploaded'],
                'club_id'            => $p['club_id'],
                'club_logo_uploaded' => (bool) $p['club_logo_uploaded'],
            ];
        }
        return $map;
    }

    private function getTeamInfoMap(array $teamIds): array
    {
        $teamIds = array_values(array_unique($teamIds));
        if (empty($teamIds)) return [];

        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $tq = $this->con_league->prepare(
            "SELECT t.id, t.team_name, t.color_primary AS color, t.season_id, m.manager_name
             FROM team t JOIN manager m ON m.id = t.manager_id
             WHERE t.id IN ($ph)"
        );
        $tq->execute($teamIds);
        $map = [];
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $map[$t['id']] = [
                'team_id'      => $t['id'],
                'team_name'    => $t['team_name'],
                'color'        => $this->resolveColor($t['color']),
                'season_id'    => $t['season_id'],
                'manager_name' => $t['manager_name'],
            ];
        }
        return $map;
    }

    /**
     * direction=incoming → offene Angebote anderer Manager für Spieler dieses Teams (nur pending);
     * direction=outgoing → alle eigenen Angebote dieses Teams (jeder Status, neueste zuerst).
     */
    public function getPlayerOffers(string $teamId, string $direction): array
    {
        $this->expireStalePlayerOffers();
        $seasonId = $this->getActiveSeasonId();

        if ($direction === 'incoming') {
            $q = $this->con_league->prepare(
                "SELECT * FROM player_offer WHERE seller_team_id = :tid AND status = 'pending' ORDER BY created_at DESC"
            );
        } else {
            $q = $this->con_league->prepare(
                "SELECT * FROM player_offer WHERE buyer_team_id = :tid ORDER BY created_at DESC LIMIT 50"
            );
        }
        $q->execute([':tid' => $teamId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return ['offers' => [], 'window_open' => $seasonId ? (bool) $this->findTransferwindow($seasonId, true) : false];

        $playerMap = $seasonId ? $this->getPlayerCardInfoMap(array_column($rows, 'player_id'), $seasonId) : [];
        $counterIds = array_map(fn($r) => $direction === 'incoming' ? $r['buyer_team_id'] : $r['seller_team_id'], $rows);
        $teamMap = $this->getTeamInfoMap($counterIds);

        $wids = array_values(array_unique(array_column($rows, 'expires_window_id')));
        $wph  = implode(',', array_fill(0, count($wids), '?'));
        $wq   = $this->con->prepare("SELECT id, start_date, end_date FROM transferwindow WHERE id IN ($wph)");
        $wq->execute($wids);
        $windowMap = array_column($wq->fetchAll(PDO::FETCH_ASSOC), null, 'id');

        $offers = [];
        foreach ($rows as $r) {
            $counterId = $direction === 'incoming' ? $r['buyer_team_id'] : $r['seller_team_id'];
            $pm        = $playerMap[$r['player_id']] ?? [];
            $mv        = $seasonId && $r['status'] === 'pending'
                ? ($this->getPlayerMarketValueInfo($r['player_id'], $seasonId)['market_value'] ?? null)
                : null;
            $offers[] = [
                'id'                 => $r['id'],
                'player_id'          => $r['player_id'],
                'displayname'        => $pm['displayname']        ?? null,
                'position'           => $pm['position']           ?? null,
                'photo_uploaded'     => $pm['photo_uploaded']     ?? false,
                'club_id'            => $pm['club_id']            ?? null,
                'club_logo_uploaded' => $pm['club_logo_uploaded'] ?? false,
                'season_id'          => $seasonId,
                'counterpart'        => $teamMap[$counterId]      ?? null,
                'offer_value'        => (int) $r['offer_value'],
                'price_snapshot'     => (int) $r['price_snapshot'],
                'market_value'       => $mv,
                'status'             => $r['status'],
                'expires_at'         => $windowMap[$r['expires_window_id']]['end_date'] ?? null,
                'created_at'         => $r['created_at'],
                'responded_at'       => $r['responded_at'],
            ];
        }

        return ['offers' => $offers, 'window_open' => $seasonId ? (bool) $this->findTransferwindow($seasonId, true) : false];
    }

    /**
     * Grundlage für den "Angebot machen"-Button auf der Spielerseite: Marktwert (serverseitig, Verkaufs-
     * formel), verfügbares Budget, Ziel-Fenster, evtl. schon offenes Angebot — plus can_offer/reason.
     */
    public function getPlayerOfferQuote(string $buyerTeamId, string $playerId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $out = [
            'can_offer' => false, 'reason' => null, 'market_value' => null, 'available_budget' => 0,
            'seller_team' => null, 'target_window' => null, 'existing_offer_id' => null,
        ];
        if (!$seasonId) { $out['reason'] = 'no_season'; return $out; }

        $this->expireStalePlayerOffers();

        $info = $this->getPlayerMarketValueInfo($playerId, $seasonId);
        $out['market_value']     = $info['market_value'] ?? null;
        $out['available_budget'] = $this->getTeamBudgetValue($buyerTeamId) - $this->getReservedBudget($buyerTeamId);

        $sellerTeamId = $this->getActiveOwnerTeamId($playerId, $seasonId);
        if ($sellerTeamId !== null) $out['seller_team'] = $this->getTeamInfoMap([$sellerTeamId])[$sellerTeamId] ?? null;

        $window = $this->findTransferwindow($seasonId, false);
        if ($window) $out['target_window'] = ['id' => $window['id'], 'start_date' => $window['start_date'], 'end_date' => $window['end_date'], 'is_open' => $window['is_open']];

        $eq = $this->con_league->prepare(
            "SELECT id FROM player_offer WHERE buyer_team_id = :tid AND player_id = :pid AND status = 'pending' LIMIT 1"
        );
        $eq->execute([':tid' => $buyerTeamId, ':pid' => $playerId]);
        $out['existing_offer_id'] = $eq->fetchColumn() ?: null;

        if ($sellerTeamId === null)                     $out['reason'] = 'not_owned';
        elseif ($sellerTeamId === $buyerTeamId)         $out['reason'] = 'own_player';
        elseif (!$info)                                 $out['reason'] = 'no_market_value';
        elseif ($out['existing_offer_id'])              $out['reason'] = 'already_offered';
        elseif (!$window)                               $out['reason'] = 'no_window';
        elseif (isset(self::SQUAD_MAX[$info['position'] ?? ''])
                && $this->countTeamPositionSlots($buyerTeamId, $seasonId, $info['position']) >= self::SQUAD_MAX[$info['position']])
                                                        $out['reason'] = 'position_full';
        elseif ($out['available_budget'] < $info['market_value']) $out['reason'] = 'insufficient_budget';
        else                                            $out['can_offer'] = true;

        return $out;
    }

    /**
     * Team-weite Bedingungen für Direktangebote, gebündelt für Listen (z.B. /markt/spieler), damit nicht je
     * Spieler eine Quote geladen werden muss: Ziel-Fenster, verfügbares Budget, volle Positionen, Spieler mit
     * bereits offenem Angebot, Anzahl offener Angebote. Die Bedingungen je Spieler (Besitzer, Marktwert) prüft
     * der Client anhand seiner Listendaten; maßgeblich bleiben immer /player_offer/quote und POST /player_offer.
     */
    public function getPlayerOfferEligibility(string $buyerTeamId): array
    {
        $seasonId = $this->getActiveSeasonId();
        $out = [
            'target_window' => null, 'available_budget' => 0, 'full_positions' => [],
            'open_player_ids' => [], 'open_count' => 0, 'max_open' => self::MAX_OPEN_PLAYER_OFFERS,
        ];
        if (!$seasonId) return $out;

        $this->expireStalePlayerOffers();
        $out['available_budget'] = $this->getTeamBudgetValue($buyerTeamId) - $this->getReservedBudget($buyerTeamId);

        $window = $this->findTransferwindow($seasonId, false);
        if ($window) $out['target_window'] = ['id' => $window['id'], 'start_date' => $window['start_date'], 'end_date' => $window['end_date'], 'is_open' => $window['is_open']];

        foreach (self::SQUAD_MAX as $position => $max) {
            if ($this->countTeamPositionSlots($buyerTeamId, $seasonId, $position) >= $max) $out['full_positions'][] = $position;
        }

        if ($this->hasPlayerOfferTable()) {
            $q = $this->con_league->prepare("SELECT player_id FROM player_offer WHERE buyer_team_id = :tid AND status = 'pending'");
            $q->execute([':tid' => $buyerTeamId]);
            $out['open_player_ids'] = $q->fetchAll(PDO::FETCH_COLUMN);
            $out['open_count'] = count($out['open_player_ids']);
        }
        return $out;
    }

    // ─── Antworten / Stornieren ────────────────────────────────────────────────────

    public function cancelPlayerOffer(string $offerId, string $buyerTeamId): bool
    {
        $q = $this->con_league->prepare(
            "UPDATE player_offer SET status = 'cancelled', responded_at = NOW()
             WHERE id = :id AND buyer_team_id = :tid AND status = 'pending'"
        );
        $q->execute([':id' => $offerId, ':tid' => $buyerTeamId]);
        return $q->rowCount() > 0;
    }

    public function declinePlayerOffer(string $offerId, string $sellerTeamId): array
    {
        $this->expireStalePlayerOffers();
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId || !$this->findTransferwindow($seasonId, true)) {
            return $this->dealError(403, 'Angebote können nur innerhalb einer Transferphase beantwortet werden');
        }

        $oq = $this->con_league->prepare(
            "SELECT buyer_team_id, player_id FROM player_offer
             WHERE id = :id AND seller_team_id = :tid AND status = 'pending' LIMIT 1"
        );
        $oq->execute([':id' => $offerId, ':tid' => $sellerTeamId]);
        $offer = $oq->fetch(PDO::FETCH_ASSOC);
        if (!$offer) return $this->dealError(404, 'Angebot nicht gefunden oder bereits beantwortet');

        $uq = $this->con_league->prepare(
            "UPDATE player_offer SET status = 'declined', responded_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $uq->execute([':id' => $offerId]);
        if ($uq->rowCount() === 0) return $this->dealError(409, 'Angebot wurde bereits beantwortet');

        $info = $this->getPlayerMarketValueInfo($offer['player_id'], $seasonId);
        $this->notifyTeamManager(
            $offer['buyer_team_id'],
            'Angebot abgelehnt',
            $this->getTeamName($sellerTeamId) . ' hat dein Angebot für ' . ($info['displayname'] ?? 'den Spieler') . ' abgelehnt.'
        );
        return ['status' => true];
    }

    /**
     * Annahme = sofortiger Vollzug. Unter Named-Locks (Spieler + Käuferteam + Verkäuferteam) werden
     * Besitz, Fenster, Budget (inkl. anderer Reservierungen) und Positionslimit erneut geprüft und
     * dann in EINER con_league-Transaktion: Stint des Verkäufers schließen, Stint des Käufers öffnen,
     * beide Konten buchen, Lineup des Verkäufers bereinigen, konkurrierende Angebote auf den Spieler
     * hinfällig machen. Benachrichtigungen erst nach dem Commit.
     */
    public function acceptPlayerOffer(string $offerId, string $sellerTeamId): array
    {
        $seasonId = $this->getActiveSeasonId();
        if (!$seasonId) return $this->dealError(422, 'Keine aktive Saison');

        $pre = $this->con_league->prepare(
            "SELECT player_id, buyer_team_id FROM player_offer WHERE id = :id AND seller_team_id = :tid LIMIT 1"
        );
        $pre->execute([':id' => $offerId, ':tid' => $sellerTeamId]);
        $preOffer = $pre->fetch(PDO::FETCH_ASSOC);
        if (!$preOffer) return $this->dealError(404, 'Angebot nicht gefunden');

        $lockNames = [
            'deal_player_' . $preOffer['player_id'],
            'deal_team_' . $preOffer['buyer_team_id'],
            'deal_team_' . $sellerTeamId,
        ];
        if (!$this->acquireDealLocks($lockNames)) return $this->dealError(409, 'Bitte gleich nochmal versuchen');

        $voided = [];
        try {
            $this->expireStalePlayerOffers();

            // Ab hier unter Lock: alles neu einlesen, nichts aus dem Vorab-Read übernehmen.
            $oq = $this->con_league->prepare(
                "SELECT id, player_id, buyer_team_id, seller_team_id, offer_value, status
                 FROM player_offer WHERE id = :id AND seller_team_id = :tid LIMIT 1"
            );
            $oq->execute([':id' => $offerId, ':tid' => $sellerTeamId]);
            $offer = $oq->fetch(PDO::FETCH_ASSOC);
            if (!$offer) return $this->dealError(404, 'Angebot nicht gefunden');
            if ($offer['status'] !== 'pending') return $this->dealError(409, 'Angebot ist nicht mehr offen');

            $window = $this->findTransferwindow($seasonId, true);
            if (!$window) return $this->dealError(403, 'Angebote können nur innerhalb einer Transferphase beantwortet werden');

            $playerId = $offer['player_id'];
            $buyerId  = $offer['buyer_team_id'];
            $value    = (int) $offer['offer_value'];

            if ($this->getActiveOwnerTeamId($playerId, $seasonId) !== $sellerTeamId) {
                $this->voidPendingPlayerOffers($playerId);
                return $this->dealError(409, 'Der Spieler gehört nicht mehr zu deinem Team');
            }

            $bq = $this->con_league->prepare("SELECT season_id FROM team WHERE id = :id LIMIT 1");
            $bq->execute([':id' => $buyerId]);
            if ($bq->fetchColumn() !== $seasonId) return $this->dealError(409, 'Team des Bieters gehört nicht zur aktiven Saison');

            $othersReserved = $this->getReservedBudget($buyerId, null, $offerId);
            if ($this->getTeamBudgetValue($buyerId) - $othersReserved < $value) {
                return $this->dealError(409, 'Der Bieter hat nicht mehr genug Budget');
            }
            if ($this->isPositionFull($buyerId, $playerId)) {
                return $this->dealError(409, 'Der Bieter hat auf dieser Position keinen Platz mehr');
            }

            $info = $this->getPlayerMarketValueInfo($playerId, $seasonId);
            $displayname = $info['displayname'] ?? 'Spieler';
            $matchdayId  = $window['matchday_id'];

            $this->con_league->beginTransaction();
            try {
                $u = $this->con_league->prepare(
                    "UPDATE player_offer SET status = 'accepted', responded_at = NOW(), settled_window_id = :wid
                     WHERE id = :id AND status = 'pending'"
                );
                $u->execute([':wid' => $window['id'], ':id' => $offerId]);
                if ($u->rowCount() !== 1) throw new \RuntimeException('offer_not_pending');

                $c = $this->con_league->prepare(
                    "UPDATE player_in_team SET to_matchday_id = :mid
                     WHERE team_id = :tid AND player_id = :pid AND to_matchday_id IS NULL"
                );
                $c->execute([':mid' => $matchdayId, ':tid' => $sellerTeamId, ':pid' => $playerId]);
                if ($c->rowCount() !== 1) throw new \RuntimeException('seller_stint_missing');

                $this->con_league->prepare(
                    "INSERT INTO player_in_team (team_id, player_id, from_matchday_id, player_offer_id)
                     VALUES (:tid, :pid, :mid, :poid)"
                )->execute([':tid' => $buyerId, ':pid' => $playerId, ':mid' => $matchdayId, ':poid' => $offerId]);

                $tx = $this->con_league->prepare(
                    "INSERT INTO transaction (team_id, amount, reason, matchday_id) VALUES (:tid, :amount, :reason, :mid)"
                );
                $tx->execute([':tid' => $buyerId, ':amount' => -$value, ':reason' => "Spielerkauf (Angebot): $displayname", ':mid' => $matchdayId]);
                $tx->execute([':tid' => $sellerTeamId, ':amount' => $value, ':reason' => "Spielerverkauf (Angebot): $displayname", ':mid' => $matchdayId]);

                $this->removePlayerFromOpenLineups($sellerTeamId, $playerId);
                $voided = $this->voidPendingPlayerOffers($playerId, $offerId);

                $this->con_league->commit();
            } catch (\Throwable $e) {
                $this->con_league->rollBack();
                if ($e instanceof \RuntimeException) return $this->dealError(409, 'Angebot konnte nicht vollzogen werden (Zustand hat sich geändert)');
                throw $e;
            }
        } finally {
            $this->releaseDealLocks($lockNames);
        }

        // Nach dem Commit — Fehler hier dürfen den vollzogenen Deal nicht zurückrollen.
        try {
            $sellerName = $this->getTeamName($sellerTeamId);
            $buyerName  = $this->getTeamName($buyerId);
            $valueText  = number_format($value, 0, ',', '.') . ' €';
            $this->notifyTeamManager($buyerId, 'Angebot angenommen', "$sellerName hat dein Angebot für $displayname ($valueText) angenommen.");
            foreach ($voided as $v) {
                $this->notifyTeamManager($v['buyer_team_id'], 'Angebot hinfällig', "$displayname wurde anderweitig vergeben — dein Angebot ist hinfällig.");
            }
            $this->notifyWatchersPlayerSold($playerId, $sellerTeamId, $displayname);
            $this->notifyWatchersPlayerBought($playerId, $buyerId, $displayname, $buyerName);
        } catch (\Throwable $e) {
            error_log('acceptPlayerOffer notify: ' . $e->getMessage());
        }

        return ['status' => true, 'price' => $value];
    }

    // ─── Hinterzimmerdeals in der Transferphasen-Ansicht ──────────────────────────

    /** Vollzogene Direktdeals, die in diesem Transferfenster angenommen wurden. */
    public function getWindowDirectDeals(string $windowId): array
    {
        if (!$this->hasPlayerOfferTable()) return [];

        $q = $this->con_league->prepare(
            "SELECT id, player_id, buyer_team_id, seller_team_id, offer_value, price_snapshot, responded_at
             FROM player_offer WHERE settled_window_id = :wid AND status = 'accepted'
             ORDER BY responded_at DESC"
        );
        $q->execute([':wid' => $windowId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        $seasonId  = $this->getActiveSeasonId();
        $playerMap = $seasonId ? $this->getPlayerCardInfoMap(array_column($rows, 'player_id'), $seasonId) : [];
        $teamMap   = $this->getTeamInfoMap(array_merge(array_column($rows, 'buyer_team_id'), array_column($rows, 'seller_team_id')));

        return array_map(function ($r) use ($playerMap, $teamMap, $seasonId) {
            $pm = $playerMap[$r['player_id']] ?? [];
            return [
                'id'                 => $r['id'],
                'player_id'          => $r['player_id'],
                'season_id'          => $seasonId,
                'displayname'        => $pm['displayname']        ?? null,
                'position'           => $pm['position']           ?? null,
                'photo_uploaded'     => $pm['photo_uploaded']     ?? false,
                'club_id'            => $pm['club_id']            ?? null,
                'club_logo_uploaded' => $pm['club_logo_uploaded'] ?? false,
                'seller'             => $teamMap[$r['seller_team_id']] ?? null,
                'buyer'              => $teamMap[$r['buyer_team_id']]  ?? null,
                'price'              => (int) $r['offer_value'],
                'price_snapshot'     => (int) $r['price_snapshot'],
                'accepted_at'        => $r['responded_at'],
            ];
        }, $rows);
    }
}
