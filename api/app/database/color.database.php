<?php

trait ColorTrait
{
    public function getColors(): array
    {
        return $this->con->query(
            "SELECT name, hex FROM color ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateColorHex(string $name, string $hex): void
    {
        $this->con->prepare("UPDATE color SET hex = :hex WHERE name = :name")
            ->execute([':hex' => $hex, ':name' => $name]);
    }
}
