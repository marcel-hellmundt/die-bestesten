<?php

trait PlayerInClubTrait
{
    public function createPlayerInClub(array $body): array
    {
        $id = $this->con->query("SELECT UUID() AS id")->fetchColumn();
        $stmt = $this->con->prepare("
            INSERT INTO player_in_club (id, player_id, club_id, from_date, to_date, on_loan)
            VALUES (:id, :player_id, :club_id, :from_date, NULL, :on_loan)
        ");
        $stmt->execute([
            ':id'        => $id,
            ':player_id' => $body['player_id'],
            ':club_id'   => $body['club_id'],
            ':from_date' => $body['from_date'],
            ':on_loan'   => (int) ($body['on_loan'] ?? 0),
        ]);
        return ['id' => $id];
    }
}
