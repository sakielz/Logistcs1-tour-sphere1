<?php
declare(strict_types=1);

$sq = new PDO('sqlite:' . __DIR__ . '/../database/database.sqlite');
$stmt = $sq->query("SELECT * FROM users WHERE username = 'JayC' OR email LIKE '%Jayc%'");
$u = $stmt->fetch(PDO::FETCH_ASSOC);
echo "JayC in SQLite: " . print_r($u, true) . "\n";
$common = ['password', 'admin123', 'admin', 'admin@08', '123456', 'jayc', 'jayc123', 'jayc@123', 'Jayc123', 'JayC123', 'JaycDelaCruz', 'delacruz', 'password123'];
foreach ($common as $p) {
    if (password_verify($p, $u['password'])) {
        echo "Password for JayC is: '{$p}'\n";
        break;
    }
}
