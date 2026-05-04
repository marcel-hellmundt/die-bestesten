<?php

trait WatchlistTrait
{
    public function getWatchlist(string $teamId): array
    {
        $seasonId = $this->con->query(
            "SELECT id FROM season ORDER BY start_date DESC LIMIT 1"
        )->fetchColumn();

        $q = $this->con_league->prepare(
            "SELECT tw.id, tw.player_id, tw.created_at FROM team_watchlist tw WHERE tw.team_id = ?"
        );
        $q->execute([$teamId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [];

        $playerIds = array_column($rows, 'player_id');
        $ph        = implode(',', array_fill(0, count($playerIds), '?'));

        $pq = $this->con->prepare(
            "SELECT p.id, p.displayname,
                    pis.position, pis.price, pis.season_id, pis.photo_uploaded,
                    pic.club_id,
                    c.name AS club_name, c.short_name AS club_short_name, c.logo_uploaded AS club_logo_uploaded
             FROM player p
             LEFT JOIN player_in_season pis ON pis.player_id = p.id AND pis.season_id = ?
             LEFT JOIN player_in_club   pic ON pic.player_id = p.id AND pic.to_date IS NULL
             LEFT JOIN club             c   ON c.id = pic.club_id
             WHERE p.id IN ($ph)"
        );
        $pq->execute([$seasonId, ...$playerIds]);
        $playerMap = [];
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $playerMap[$row['id']] = $row;
        }

        $currentTeamQ = $this->con_league->prepare(
            "SELECT pit.player_id, t.team_name, t.color, t.id AS team_id, t.season_id AS team_season_id,
                    m.manager_name, m.alias
             FROM player_in_team pit
             JOIN team t    ON t.id = pit.team_id
             JOIN manager m ON m.id = t.manager_id
             WHERE pit.player_id IN ($ph) AND pit.to_matchday_id IS NULL"
        );
        $currentTeamQ->execute($playerIds);
        $teamMap = [];
        foreach ($currentTeamQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teamMap[$row['player_id']] = $row;
        }

        return array_map(function ($entry) use ($playerMap, $teamMap) {
            $p    = $playerMap[$entry['player_id']] ?? [];
            $team = $teamMap[$entry['player_id']]   ?? null;
            return [
                'id'                => $entry['id'],
                'player_id'         => $entry['player_id'],
                'created_at'        => $entry['created_at'],
                'displayname'       => $p['displayname']        ?? null,
                'photo_uploaded'    => (bool) ($p['photo_uploaded'] ?? false),
                'position'          => $p['position']           ?? null,
                'price'             => $p['price'] !== null ? (int) $p['price'] : null,
                'season_id'         => $p['season_id']          ?? null,
                'club_id'           => $p['club_id']            ?? null,
                'club_name'         => $p['club_name']          ?? null,
                'club_short_name'   => $p['club_short_name']    ?? null,
                'club_logo_uploaded'=> (bool) ($p['club_logo_uploaded'] ?? false),
                'current_team'      => $team ? [
                    'team_id'        => $team['team_id'],
                    'team_name'      => $team['team_name'],
                    'color'          => $team['color'],
                    'team_season_id' => $team['team_season_id'],
                    'manager_name'   => $team['manager_name'],
                    'alias'          => $team['alias'],
                ] : null,
            ];
        }, $rows);
    }

    public function addToWatchlist(string $teamId, string $playerId): string
    {
        $id = $this->con_league->query("SELECT UUID()")->fetchColumn();
        $this->con_league->prepare(
            "INSERT IGNORE INTO team_watchlist (id, team_id, player_id) VALUES (?, ?, ?)"
        )->execute([$id, $teamId, $playerId]);

        // Return actual id (may differ if already existed due to INSERT IGNORE)
        $existing = $this->con_league->prepare(
            "SELECT id FROM team_watchlist WHERE team_id = ? AND player_id = ?"
        );
        $existing->execute([$teamId, $playerId]);
        return $existing->fetchColumn();
    }

    public function removeFromWatchlist(string $watchlistId, string $teamId): void
    {
        $this->con_league->prepare(
            "DELETE FROM team_watchlist WHERE id = ? AND team_id = ?"
        )->execute([$watchlistId, $teamId]);
    }

    public function notifyWatchersPlayerSold(string $playerId, string $sellerTeamId, string $displayname): void
    {
        $q = $this->con_league->prepare(
            "SELECT tw.team_id, t.manager_id FROM team_watchlist tw
             JOIN team t ON t.id = tw.team_id
             WHERE tw.player_id = ? AND tw.team_id != ?"
        );
        $q->execute([$playerId, $sellerTeamId]);
        $watchers = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($watchers as $w) {
            if (!$this->isNotificationEnabled($w['manager_id'], 'scouted_player_update')) continue;
            $this->createNotification(
                $w['manager_id'],
                "Beobachteter Spieler verkauft",
                "$displayname wurde verkauft.",
                null
            );
        }
    }

    public function notifyWatchersPlayerBought(string $playerId, string $buyerTeamId, string $displayname, string $buyerTeamName): void
    {
        $q = $this->con_league->prepare(
            "SELECT tw.team_id, t.manager_id FROM team_watchlist tw
             JOIN team t ON t.id = tw.team_id
             WHERE tw.player_id = ? AND tw.team_id != ?"
        );
        $q->execute([$playerId, $buyerTeamId]);
        $watchers = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($watchers as $w) {
            if (!$this->isNotificationEnabled($w['manager_id'], 'scouted_player_update')) continue;
            $this->createNotification(
                $w['manager_id'],
                "Beobachteter Spieler gekauft",
                "$displayname wurde von $buyerTeamName gekauft.",
                null
            );
        }
    }

    public function notifyWatchersPlayerSds(string $playerId, string $displayname): void
    {
        $q = $this->con_league->prepare(
            "SELECT tw.team_id, t.manager_id FROM team_watchlist tw
             JOIN team t ON t.id = tw.team_id
             WHERE tw.player_id = ?"
        );
        $q->execute([$playerId]);
        $watchers = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($watchers as $w) {
            if (!$this->isNotificationEnabled($w['manager_id'], 'scouted_player_update')) continue;
            $this->createNotification(
                $w['manager_id'],
                "Beobachteter Spieler SdS",
                "$displayname ist Spieler des Spieltags.",
                null
            );
        }
    }

    private function isNotificationEnabled(string $managerId, string $eventType): bool
    {
        $q = $this->con_league->prepare(
            "SELECT enabled FROM notification_preference WHERE manager_id = ? AND event_type = ?"
        );
        $q->execute([$managerId, $eventType]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false || (bool) $row['enabled'];
    }
}
