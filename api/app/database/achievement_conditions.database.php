<?php

trait AchievementConditionsTrait
{
    // Alle Methoden: check_X(array $managerIds): array
    // Gibt die Teilmenge der manager_ids zurück, die die Bedingung erfüllen.
    // Cross-DB-Joins werden PHP-seitig aufgelöst (con = global, con_league = liga).

    public function check_first_matchday(array $managerIds): array
    {
        if (empty($managerIds)) return [];

        $completedIds = $this->con->query(
            "SELECT id FROM matchday WHERE completed = 1"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (empty($completedIds)) return [];

        $mPlh   = implode(',', array_fill(0, count($completedIds), '?'));
        $manPlh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt   = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_lineup tl ON tl.team_id = t.id
             WHERE m.id IN ($manPlh) AND tl.matchday_id IN ($mPlh)"
        );
        $stmt->execute([...$managerIds, ...$completedIds]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function check_first_win(array $managerIds): array
    {
        if (empty($managerIds)) return [];
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.id IN ($plh)
               AND tr.points = (
                   SELECT MAX(tr2.points) FROM team_rating tr2
                   WHERE tr2.matchday_id = tr.matchday_id AND tr2.invalid = 0
               )"
        );
        $stmt->execute($managerIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function check_century(array $managerIds): array
    {
        if (empty($managerIds)) return [];
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.id IN ($plh) AND tr.points >= 100"
        );
        $stmt->execute($managerIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function check_hat_trick(array $managerIds): array
    {
        if (empty($managerIds)) return [];
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             WHERE m.id IN ($plh)
               AND tr.points = (
                   SELECT MAX(tr2.points) FROM team_rating tr2
                   WHERE tr2.matchday_id = tr.matchday_id AND tr2.invalid = 0
               )
             GROUP BY m.id, t.season_id
             HAVING COUNT(*) >= 3"
        );
        $stmt->execute($managerIds);
        return array_unique($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function check_season_champion(array $managerIds): array
    {
        if (empty($managerIds)) return [];

        $rows = $this->con_league->query(
            "SELECT t.manager_id, t.season_id, SUM(tr.points) AS total
             FROM team t
             JOIN team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
             GROUP BY t.id, t.season_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $seasonMax = [];
        foreach ($rows as $row) {
            $sid = $row['season_id'];
            if (!isset($seasonMax[$sid]) || $row['total'] > $seasonMax[$sid]) {
                $seasonMax[$sid] = (int) $row['total'];
            }
        }

        $winners = [];
        foreach ($rows as $row) {
            if ((int) $row['total'] === ($seasonMax[$row['season_id']] ?? -1)) {
                $winners[] = $row['manager_id'];
            }
        }

        return array_values(array_intersect($managerIds, array_unique($winners)));
    }

    public function check_full_squad(array $managerIds): array
    {
        if (empty($managerIds)) return [];
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN (
                 SELECT team_id, matchday_id, COUNT(DISTINCT player_id) AS cnt
                 FROM team_lineup
                 GROUP BY team_id, matchday_id
             ) sub ON sub.team_id = t.id AND sub.cnt >= 18
             WHERE m.id IN ($plh)"
        );
        $stmt->execute($managerIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function check_first_bid_win(array $managerIds): array
    {
        if (empty($managerIds)) return [];
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT DISTINCT m.id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN offer o ON o.team_id = t.id AND o.status = 'success'
             WHERE m.id IN ($plh)"
        );
        $stmt->execute($managerIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function check_sds_hero(array $managerIds): array
    {
        if (empty($managerIds)) return [];

        // SDS-Spieler aus globaler DB holen
        $sdsRows = $this->con->query(
            "SELECT player_id, matchday_id FROM player_rating WHERE sds = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sdsRows)) return [];

        $sdsKeySet = [];
        foreach ($sdsRows as $row) {
            $sdsKeySet[$row['player_id'] . '|' . $row['matchday_id']] = true;
        }

        // Nominierungen aus liga DB holen
        $plh = implode(',', array_fill(0, count($managerIds), '?'));
        $stmt = $this->con_league->prepare(
            "SELECT m.id AS manager_id, tl.player_id, tl.matchday_id
             FROM manager m
             JOIN team t ON t.manager_id = m.id
             JOIN team_lineup tl ON tl.team_id = t.id AND tl.nominated = 1
             WHERE m.id IN ($plh)"
        );
        $stmt->execute($managerIds);
        $nominations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Zählen: wie viele SDS-Spieler hat jeder Manager je Spieltag nominiert?
        $counts = [];
        foreach ($nominations as $nom) {
            $key = $nom['player_id'] . '|' . $nom['matchday_id'];
            if (isset($sdsKeySet[$key])) {
                $mk = $nom['manager_id'] . '|' . $nom['matchday_id'];
                $counts[$mk] = ($counts[$mk] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($counts as $mk => $cnt) {
            if ($cnt >= 2) {
                $result[] = explode('|', $mk)[0];
            }
        }

        return array_unique($result);
    }
}
