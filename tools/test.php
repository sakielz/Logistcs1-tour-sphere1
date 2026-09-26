<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "Database connection successful!<br>";
echo "PDO Object: " . get_class($pdo) . "<br>";

// Test database connection
try {
    $stmt = $pdo->query("SELECT 1");
    echo "Query executed successfully!";
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage();
}