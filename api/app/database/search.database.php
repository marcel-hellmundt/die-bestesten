<?php

trait SearchTrait
{
    public function search(string $query): array
    {
        $like = '%' . $query . '%';

        // Players
        $pq = $this->con->prepare(
            "SELECT p.id, p.displayname, p.first_name, p.last_name,
                    pis.position, pis.photo_uploaded,
                    (SELECT season_id FROM player_in_season WHERE player_id = p.id ORDER BY (SELECT start_date FROM season WHERE id = season_id) DESC LIMIT 1) AS season_id
             FROM player p
             LEFT JOIN player_in_season pis
                   ON pis.player_id = p.id
                   AND pis.season_id = (SELECT id FROM season ORDER BY start_date DESC LIMIT 1)
             WHERE p.displayname LIKE :q OR p.first_name LIKE :q2 OR p.last_name LIKE :q3
             ORDER BY p.displayname
             LIMIT 8"
        );
        $pq->execute([':q' => $like, ':q2' => $like, ':q3' => $like]);
        $players = $pq->fetchAll(PDO::FETCH_ASSOC);

        // Clubs
        $cq = $this->con->prepare(
            "SELECT id, name, short_name, logo_uploaded FROM club WHERE name LIKE :q ORDER BY name LIMIT 8"
        );
        $cq->execute([':q' => $like]);
        $clubs = $cq->fetchAll(PDO::FETCH_ASSOC);

        // Managers
        $mq = $this->con_league->prepare(
            "SELECT id, manager_name, alias FROM manager WHERE status = 'active' AND (manager_name LIKE :q OR alias LIKE :q2) ORDER BY manager_name LIMIT 8"
        );
        $mq->execute([':q' => $like, ':q2' => $like]);
        $managers = $mq->fetchAll(PDO::FETCH_ASSOC);

        // Teams
        $tq = $this->con_league->prepare(
            "SELECT id, team_name, season_id FROM team WHERE team_name LIKE :q ORDER BY team_name LIMIT 8"
        );
        $tq->execute([':q' => $like]);
        $teams = $tq->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($teams)) {
            $seasonIds = array_unique(array_column($teams, 'season_id'));
            $ph = implode(',', array_fill(0, count($seasonIds), '?'));
            $sq = $this->con->prepare("SELECT id, start_date FROM season WHERE id IN ($ph)");
            $sq->execute($seasonIds);
            $seasonMap = [];
            foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $year = (int) substr($s['start_date'], 0, 4);
                $y1   = str_pad($year % 100, 2, '0', STR_PAD_LEFT);
                $y2   = str_pad(($year + 1) % 100, 2, '0', STR_PAD_LEFT);
                $seasonMap[$s['id']] = "$y1/$y2";
            }
            foreach ($teams as &$t) {
                $t['season_label'] = $seasonMap[$t['season_id']] ?? null;
            }
            unset($t);
        }

        return [
            'players'  => $players,
            'clubs'    => $clubs,
            'managers' => $managers,
            'teams'    => $teams,
        ];
    }
}
