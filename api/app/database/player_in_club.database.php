<?php

trait PlayerInClubTrait
{
    public function createPlayerInClub(array $body): array
    {
        $id = $this->con->query("SELECT UUID() AS id")->fetchColumn();
        $stmt = $this->con->prepare("
            INSERT INTO player_in_club (id, player_id, club_id, from_date, to_date, on_loan)
            VALUES (:id, :player_id, :club_id, :from_date, :to_date, :on_loan)
        ");
        $stmt->execute([
            ':id'        => $id,
            ':player_id' => $body['player_id'],
            ':club_id'   => $body['club_id'],
            ':from_date' => $body['from_date'],
            ':to_date'   => $body['to_date'] ?? null,
            ':on_loan'   => (int) ($body['on_loan'] ?? 0),
        ]);
        return ['id' => $id];
    }

    public function getPlayerInClubById(string $id): array|false
    {
        $q = $this->con->prepare("SELECT * FROM player_in_club WHERE id = :id LIMIT 1");
        $q->execute([':id' => $id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * General-purpose row editor for a player_in_club entry — any combination of
     * club_id/from_date/to_date/on_loan. to_date=null is a valid explicit value
     * (reopens a previously ended membership); the caller distinguishes "not
     * provided" (key absent from $fields) from "explicitly null" via array_key_exists.
     */
    public function updatePlayerInClub(string $id, array $fields): bool
    {
        $sets   = [];
        $params = [':id' => $id];

        if (array_key_exists('club_id', $fields)) {
            $sets[] = 'club_id = :club_id';
            $params[':club_id'] = $fields['club_id'];
        }
        if (array_key_exists('from_date', $fields)) {
            $sets[] = 'from_date = :from_date';
            $params[':from_date'] = $fields['from_date'];
        }
        if (array_key_exists('to_date', $fields)) {
            $sets[] = 'to_date = :to_date';
            $params[':to_date'] = $fields['to_date'];
        }
        if (array_key_exists('on_loan', $fields)) {
            $sets[] = 'on_loan = :on_loan';
            $params[':on_loan'] = (int) $fields['on_loan'];
        }
        if (empty($sets)) return false;

        $q = $this->con->prepare('UPDATE player_in_club SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $q->execute($params);
        return $q->rowCount() > 0;
    }

    public function deletePlayerInClub(string $id): bool
    {
        $q = $this->con->prepare("DELETE FROM player_in_club WHERE id = :id");
        $q->execute([':id' => $id]);
        return $q->rowCount() > 0;
    }

    /**
     * Bulk-lookup of each player's current (to_date IS NULL) club membership,
     * indexed by player_id.
     */
    public function getCurrentClubByPlayerIds(array $playerIds): array
    {
        if (empty($playerIds)) return [];

        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $q = $this->con->prepare(
            "SELECT pic.player_id, pic.id AS player_in_club_id, pic.club_id,
                    c.name AS club_name, c.logo_uploaded
             FROM player_in_club pic
             JOIN club c ON c.id = pic.club_id
             WHERE pic.to_date IS NULL AND pic.player_id IN ($placeholders)"
        );
        $q->execute($playerIds);

        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['player_id']] = $row;
        }
        return $out;
    }

    /**
     * Players currently assigned to a club that plays in the given division for the
     * given season, but whose player_id is not in $excludePlayerIds (typically the
     * kicker_id-matched players from an uploaded CSV) — likely stale club data
     * (player has since transferred away and the DB hasn't been updated yet).
     */
    public function getMissingClubMembers(string $seasonId, string $divisionId, array $excludePlayerIds): array
    {
        $excludeClause = '';
        $excludeParams = [];
        if (!empty($excludePlayerIds)) {
            $placeholders  = implode(',', array_fill(0, count($excludePlayerIds), '?'));
            $excludeClause = "AND pic.player_id NOT IN ($placeholders)";
            $excludeParams = $excludePlayerIds;
        }

        $q = $this->con->prepare(
            "SELECT pic.player_id, pic.id AS player_in_club_id, p.displayname,
                    c.id AS club_id, c.name AS club_name, c.logo_uploaded
             FROM player_in_club pic
             JOIN player p ON p.id = pic.player_id
             JOIN club c   ON c.id = pic.club_id
             JOIN club_in_season cis ON cis.club_id = pic.club_id
                                     AND cis.season_id = ? AND cis.division_id = ?
             WHERE pic.to_date IS NULL
               $excludeClause
             ORDER BY p.displayname ASC"
        );
        $q->execute(array_merge([$seasonId, $divisionId], $excludeParams));

        return array_map(fn($r) => [
            'player_id'         => $r['player_id'],
            'player_in_club_id' => $r['player_in_club_id'],
            'displayname'       => $r['displayname'],
            'club_id'           => $r['club_id'],
            'club_name'         => $r['club_name'],
            'club_logo_uploaded' => (bool) $r['club_logo_uploaded'],
        ], $q->fetchAll(PDO::FETCH_ASSOC));
    }
}
