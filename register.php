<?php
// 1. Include your database connection file
require_once 'config/database.php'; // Update path if database.php is in a different folder

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2. Grab and clean the form inputs
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role      = 'admin'; // Default role

    if (empty($username) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } else {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) OR LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
            $checkStmt->execute([$email, $username]);
            $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                $error = 'An account with that email address or username already exists. Please use a different one.';
            } else {
                // 3. SECURELY HASH THE PASSWORD (PHP standard)
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                // 4. Insert the user record into the database (Supabase PostgreSQL / Cloud DB)
                $sql = "INSERT INTO users (username, email, password, role, full_name, is_active, is_archived) 
                        VALUES (?, ?, ?, ?, ?, true, false)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([$username, $email, $hashedPassword, $role, $full_name]);

                $message = "Account created successfully! <a href='login.php'>Click here to login</a>";
            }
        } catch (PDOException $e) {
            // Catch duplicate emails or database errors (both SQLite and PostgreSQL)
            if (stripos($e->getMessage(), 'unique constraint') !== false || stripos($e->getMessage(), 'duplicate key') !== false) {
                $error = 'An account with that email address or username already exists. Please use a different one.';
            } else {
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; padding: 50px; }
        .register-container { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; }
        .input-group input { width: 100%; padding: 8px; box-sizing: border-box; }
        .btn { width: 100%; padding: 10px; background-color: #24b47e; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .msg { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error { background-color: #f8d7da; color: #721c24; }
        .success { background-color: #d4edda; color: #155724; }
    </style>
</head>
<body>

<div class="register-container">
    <h2>Create Account</h2>

    <?php if ($error): ?>
        <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="msg success"><?php echo $message; ?></div>
    <?php endif; ?>

    <form action="register.php" method="POST">
        <div class="input-group">
            <label>Username *</label>
            <input type="text" name="username" required>
        </div>
        <div class="input-group">
            <label>Full Name</label>
            <input type="text" name="full_name">
        </div>
        <div class="input-group">
            <label>Email Address *</label>
            <input type="email" name="email" required>
        </div>
        <div class="input-group">
            <label>Password *</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn">Register</button>
    </form>
</div>

</body>
</html>