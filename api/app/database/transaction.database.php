<?php

trait TransactionTrait
{
    public function getTransactionsForTeam(string $teamId): array
    {
        $stmt = $this->con_league->prepare(
            "SELECT t.id, t.amount, t.reason, t.matchday_id, t.created_at
             FROM transaction t
             WHERE t.team_id = :team_id
             ORDER BY t.created_at DESC"
        );
        $stmt->execute([':team_id' => $teamId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Resolve matchday numbers in bulk
        $matchdayIds = array_filter(array_column($rows, 'matchday_id'));
        $numberById  = [];
        if (!empty($matchdayIds)) {
            $placeholders = implode(',', array_fill(0, count($matchdayIds), '?'));
            $mq = $this->con->prepare(
                "SELECT id, number FROM matchday WHERE id IN ($placeholders)"
            );
            $mq->execute(array_values($matchdayIds));
            foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $numberById[$row['id']] = (int) $row['number'];
            }
        }

        foreach ($rows as &$row) {
            $row['amount']           = (float) $row['amount'];
            $row['matchday_number']  = isset($row['matchday_id']) ? ($numberById[$row['matchday_id']] ?? null) : null;
        }
        unset($row);

        return $rows;
    }

    public function getTeamManagerId(string $teamId): ?string
    {
        $stmt = $this->con_league->prepare("SELECT manager_id FROM team WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['manager_id'] : null;
    }
}
