<?php

trait StadiumTrait
{
    public function createStadium(
        string $id,
        string $officialName,
        ?string $name,
        ?int $capacity,
        ?float $lat,
        ?float $lng,
        ?string $openedDate
    ): void {
        $query = $this->con->prepare(
            "INSERT INTO stadium (id, official_name, name, capacity, lat, lng, opened_date)
             VALUES (:id, :official_name, :name, :capacity, :lat, :lng, :opened_date)"
        );
        $query->execute([
            ':id'            => $id,
            ':official_name' => $officialName,
            ':name'          => $name,
            ':capacity'      => $capacity,
            ':lat'           => $lat,
            ':lng'           => $lng,
            ':opened_date'   => $openedDate,
        ]);
    }

    public function linkClubStadium(string $id, string $clubId, string $stadiumId, string $fromDate): void
    {
        $query = $this->con->prepare(
            "INSERT INTO club_stadium (id, club_id, stadium_id, from_date)
             VALUES (:id, :club_id, :stadium_id, :from_date)"
        );
        $query->execute([
            ':id'         => $id,
            ':club_id'    => $clubId,
            ':stadium_id' => $stadiumId,
            ':from_date'  => $fromDate,
        ]);
    }
}
