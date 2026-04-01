<?php

trait LeagueTrait
{
    public function getLeagueList(): array
    {
        $query = $this->con->prepare("SELECT * FROM league ORDER BY name ASC");
        $query->execute();
        $leagues = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leagues as &$league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['db_name']);
        }

        return $leagues;
    }

    public function getLeagueById(string $id): array|false
    {
        $query = $this->con->prepare("SELECT * FROM league WHERE id = :id LIMIT 1");
        $query->execute([':id' => $id]);
        $league = $query->fetch(PDO::FETCH_ASSOC);
        if ($league) {
            $league['manager_count'] = $this->getLeagueManagerCount($league['db_name']);
            $league['managers']      = $this->getLeagueManagerList($league['db_name']);
        }
        return $league;
    }

    public function migrateLeagueTeams(string $leagueId): array
    {
        $league = $this->getLeagueById($leagueId);
        if (!$league) return ['status' => false, 'message' => 'Liga nicht gefunden'];

        $conLeague = $this->openLeagueConnection($league['db_name']);
        if (!$conLeague) return ['status' => false, 'message' => 'Verbindung zur Liga-DB fehlgeschlagen'];

        $rows = $this->con_old->query(
            "SELECT team_id, manager_id, season_id, team_name, color FROM team"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conLeague->prepare(
            "INSERT INTO team (id, manager_id, season_id, team_name, color)
             VALUES (:id, :manager_id, :season_id, :team_name, :color)
             ON DUPLICATE KEY UPDATE
               team_name = VALUES(team_name),
               color     = VALUES(color)"
        );

        $migrated = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $color = $row['color'];
            if ($color && !str_starts_with($color, '#')) {
                $color = '#' . $color;
            }
            if ($color && strlen($color) > 7) {
                $skipped++;
                continue;
            }

            $stmt->execute([
                ':id'         => $row['team_id'],
                ':manager_id' => $row['manager_id'],
                ':season_id'  => $row['season_id'],
                ':team_name'  => $row['team_name'],
                ':color'      => $color,
            ]);
            $migrated++;
        }

        return ['status' => true, 'migrated' => $migrated, 'skipped' => $skipped];
    }

    private function getLeagueManagerCount(string $dbName): int
    {
        try {
            $pdo = $this->openLeagueConnection($dbName);
            if (!$pdo) return 0;
            return (int) $pdo->query("SELECT COUNT(*) FROM manager")->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    private function getLeagueManagerList(string $dbName): array
    {
        try {
            $pdo = $this->openLeagueConnection($dbName);
            if (!$pdo) return [];
            return $pdo->query(
                "SELECT id, manager_name, alias, role, status
                 FROM manager
                 ORDER BY FIELD(role, 'admin', 'maintainer', 'manager'), manager_name ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    private function openLeagueConnection(string $dbName): ?\PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$dbName};charset=utf8",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException) {
            return null;
        }
    }
}
