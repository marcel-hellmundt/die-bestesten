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
        }
        return $league;
    }

    private function getLeagueManagerCount(string $dbName): int
    {
        try {
            $pdo = new PDO(
                "mysql:host={$_ENV['DB_HOST']};dbname={$dbName};charset=utf8",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return (int) $pdo->query("SELECT COUNT(*) FROM manager")->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }
}
