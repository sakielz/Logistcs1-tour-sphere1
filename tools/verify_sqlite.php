<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$sql = "UPDATE users SET last_login = datetime('now') WHERE email = 'admin@globalscm.com'";
$pdo->exec($sql);

$stmt = $pdo->prepare("SELECT email, last_login FROM users WHERE email = ?");
$stmt->execute(['admin@globalscm.com']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo $user && !empty($user['last_login']) ? 'SQL_OK' : 'SQL_FAIL';
