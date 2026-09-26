<?php
declare(strict_types=1);

// tools/reset_admin_password.php
require_once __DIR__ . '/../config/database.php';

$email = 'admin@globalscm.com';
$newPassword = 'admin@08';

// Generate the correct hash
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

echo "Generated Hash: " . $hashedPassword . "<br><br>";

// Update the admin password
try {
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->execute([$hashedPassword, $email]);
    
    if ($stmt->rowCount() > 0) {
        echo "✅ Password updated successfully!<br>";
        echo "Email: " . $email . "<br>";
        echo "Password: " . $newPassword . "<br>";
        echo "Hash: " . $hashedPassword . "<br>";
    } else {
        echo "⚠️ Admin user not found. Creating admin user...<br>";
        
        // Create admin if not exists
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, full_name, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', $email, $hashedPassword, 'admin', 'System Administrator', 1]);
        
        echo "✅ Admin user created successfully!<br>";
        echo "Email: " . $email . "<br>";
        echo "Password: " . $newPassword . "<br>";
        echo "Hash: " . $hashedPassword . "<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}

// Show all users for verification
echo "<br><strong>All users in database:</strong><br>";
$stmt = $pdo->query("SELECT id, username, email, role, full_name, is_active FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($users);
echo "</pre>";

// Test the password
echo "<br><strong>Testing password verification:</strong><br>";
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if (password_verify($newPassword, $user['password'])) {
        echo "✅ Password verification successful!<br>";
    } else {
        echo "❌ Password verification failed. Stored hash: " . $user['password'] . "<br>";
    }
}
?>