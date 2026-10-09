<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function testLogin($loginIdentifier, $password) {
    global $pdo;
    echo "Testing login for identifier: '{$loginIdentifier}', password: '{$password}'\n";
    $sql  = "SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) OR LOWER(TRIM(username)) = LOWER(TRIM(:username)) LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':email' => $loginIdentifier,
        ':username' => $loginIdentifier,
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "  [FAIL] No account found with that email address or username.\n";
        return;
    }
    
    echo "  [OK] User found: ID {$user['id']}, role={$user['role']}, active=" . var_export($user['is_active'], true) . ", archived=" . var_export($user['is_archived'], true) . "\n";

    if (!(bool)$user['is_active']) {
        echo "  [FAIL] Account is inactive.\n";
        return;
    }

    if ((bool)$user['is_archived']) {
        echo "  [FAIL] Account is archived.\n";
        return;
    }

    if (empty($user['password'])) {
        echo "  [FAIL] No password set.\n";
        return;
    }

    if (!password_verify($password, $user['password'])) {
        echo "  [FAIL] Incorrect password.\n";
        return;
    }

    echo "  [SUCCESS] Password verified!\n";
    if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
        echo "  [INFO] 2FA is required.\n";
    } else {
        echo "  [SUCCESS] Full login would succeed without 2FA -> redirect to admin/dashboard.php\n";
    }
}

echo "Testing against active PDO driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

testLogin('admin', 'admin@08');
testLogin('admin@globalscm.com', 'admin@08');
testLogin('admin3', 'admin123');
testLogin('al', 'password');
testLogin('Peter', 'admin123');
testLogin('JayC', 'admin123');
testLogin('Lenzy', 'admin123');
