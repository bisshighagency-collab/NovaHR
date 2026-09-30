<?php
class DepartmentModel
{
    public static function all(): array
    {
        return db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();
    }

    public static function create(string $name, ?string $description): int
    {
        $stmt = db()->prepare('INSERT INTO departments (name, description) VALUES (?, ?)');
        $stmt->execute([$name, $description]);
        return (int) db()->lastInsertId();
    }

    public static function update(int $id, string $name, ?string $description): void
    {
        $stmt = db()->prepare('UPDATE departments SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $description, $id]);
    }
}
