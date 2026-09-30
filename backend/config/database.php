<?php
/**
 * Database connection (PDO / MySQL).
 * Edit the constants below to match your local MySQL setup, or override them
 * with environment variables of the same name.
 */

define('DB_HOST', getenv('NOVAHR_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('NOVAHR_DB_NAME') ?: 'novahr');
define('DB_USER', getenv('NOVAHR_DB_USER') ?: 'root');
define('DB_PASS', getenv('NOVAHR_DB_PASS') ?: '');
define('DB_PORT', getenv('NOVAHR_DB_PORT') ?: '3306');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed. Check backend/config/database.php.']);
            exit;
        }
    }
    return $pdo;
}
