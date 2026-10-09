<?php
// forgot-password.php
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header('Location: admin/dashboard.php');
    exit();
}

// Define color constants if not already defined
if (!defined('COLOR_PRIMARY')) define('COLOR_PRIMARY', '#2F80ED');
if (!defined('COLOR_SECONDARY')) define('COLOR_SECONDARY', '#56CCF2');
if (!defined('COLOR_ACCENT')) define('COLOR_ACCENT', '#27AE60');
if (!defined('COLOR_BG')) define('COLOR_BG', '#F8FAFC');
if (!defined('COLOR_CARD')) define('COLOR_CARD', '#FFFFFF');
if (!defined('COLOR_TEXT')) define('COLOR_TEXT', '#1F2937');
if (!defined('COLOR_SECONDARY_TEXT')) define('COLOR_SECONDARY_TEXT', '#6B7280');
if (!defined('COLOR_BORDER')) define('COLOR_BORDER', '#EEF2F7');

$theme = getTheme();
$step = isset($_GET['step']) ? $_GET['step'] : 'request';
$error = '';
$success = '';
$showResetForm = false;

// Step 1: Request password reset (Admin Only)
if ($step === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, email, role FROM users WHERE email = ? AND is_active = 1 AND is_archived = 0");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            if ($user['role'] === 'admin') {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $_SESSION['reset_token'] = $token;
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_expires'] = $expires;
                
                $success = 'Password reset link has been sent to your email. Please check your inbox.';
                logAudit($user['id'], 'password_reset_request', 'auth', "Admin password reset requested for: $email");
                
                $resetLink = 'http://' . $_SERVER['HTTP_HOST'] . '/forgot-password.php?step=reset&token=' . $token;
                $showResetLink = true;
            } else {
                $error = 'Only administrators can reset passwords. Please contact your system administrator.';
                logAudit(null, 'password_reset_denied', 'auth', "Non-admin user attempted password reset: $email");
            }
        } else {
            $error = 'No active account found with that email address';
        }
    }
}

// Step 2: Reset password (Admin Only)
if ($step === 'reset') {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    
    if (empty($token)) {
        $error = 'Invalid or missing reset token';
    } elseif (strlen($token) === 64 && ctype_xdigit($token)) {
        if (isset($_SESSION['reset_token']) && $_SESSION['reset_token'] === $token) {
            if (isset($_SESSION['reset_expires']) && strtotime($_SESSION['reset_expires']) > time()) {
                $showResetForm = true;
                $resetEmail = $_SESSION['reset_email'] ?? '';
                
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $password = isset($_POST['password']) ? $_POST['password'] : '';
                    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
                    
                    $validationErrors = [];
                    
                    if (empty($password)) {
                        $validationErrors[] = 'Password is required';
                    } elseif (strlen($password) < 8) {
                        $validationErrors[] = 'Password must be at least 8 characters';
                    } elseif (!preg_match('/[A-Z]/', $password)) {
                        $validationErrors[] = 'Password must contain at least one uppercase letter';
                    } elseif (!preg_match('/[a-z]/', $password)) {
                        $validationErrors[] = 'Password must contain at least one lowercase letter';
                    } elseif (!preg_match('/[0-9]/', $password)) {
                        $validationErrors[] = 'Password must contain at least one number';
                    }
                    
                    if ($password !== $confirm_password) {
                        $validationErrors[] = 'Passwords do not match';
                    }
                    
                    if (empty($validationErrors)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$resetEmail]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user) {
                            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $stmt->execute([$hashedPassword, $user['id']]);
                            
                            unset($_SESSION['reset_token']);
                            unset($_SESSION['reset_email']);
                            unset($_SESSION['reset_expires']);
                            
                            $success = 'Your password has been reset successfully. You can now login with your new password.';
                            logAudit($user['id'], 'password_reset_complete', 'auth', "Admin password reset completed");
                            $showResetForm = false;
                        } else {
                            $error = 'User not found';
                        }
                    } else {
                        $error = implode('<br>', $validationErrors);
                    }
                }
            } else {
                $error = 'Reset token has expired. Please request a new password reset.';
                unset($_SESSION['reset_token']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_expires']);
            }
        } else {
            $error = 'Invalid reset token';
        }
    } else {
        $error = 'Invalid reset token format';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - GlobalSCM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?php echo COLOR_PRIMARY; ?>;
            --secondary: <?php echo COLOR_SECONDARY; ?>;
            --accent: <?php echo COLOR_ACCENT; ?>;
            --bg: <?php echo COLOR_BG; ?>;
            --card: <?php echo COLOR_CARD; ?>;
            --text: <?php echo COLOR_TEXT; ?>;
            --secondary-text: <?php echo COLOR_SECONDARY_TEXT; ?>;
            --border: <?php echo COLOR_BORDER; ?>;
            --danger: #DC2626;
        }
        
        [data-theme="dark"] {
            --bg: <?php echo COLOR_DARK_BG; ?>;
            --card: <?php echo COLOR_DARK_CARD; ?>;
            --text: <?php echo COLOR_DARK_TEXT; ?>;
            --secondary-text: <?php echo COLOR_DARK_SECONDARY_TEXT; ?>;
            --border: <?php echo COLOR_DARK_BORDER; ?>;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }
        
        .container {
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }
        
        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 40px 36px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .logo span {
            font-size: 12px;
            color: var(--secondary-text);
            display: block;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        
        .icon-wrapper {
            width: 64px;
            height: 64px;
            background: rgba(47, 128, 237, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: var(--primary);
            font-size: 28px;
        }
        
        .title {
            text-align: center;
            margin-bottom: 8px;
        }
        
        .title h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }
        
        .subtitle {
            text-align: center;
            font-size: 14px;
            color: var(--secondary-text);
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .subtitle .highlight {
            color: var(--primary);
            font-weight: 500;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border-left: 4px solid var(--accent);
        }
        
        .alert-error {
            background: #FEE2E2;
            color: #DC2626;
            border-left: 4px solid var(--danger);
        }
        
        .alert-info {
            background: #DBEAFE;
            color: #1E40AF;
            border-left: 4px solid var(--primary);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 6px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s;
            background: var(--bg);
            color: var(--text);
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(47, 128, 237, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn:hover {
            background: #2563EB;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(47, 128, 237, 0.3);
        }
        
        .btn-secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--bg);
            box-shadow: none;
            transform: none;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--secondary-text);
        }
        
        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
        
        .password-requirements {
            font-size: 12px;
            color: var(--secondary-text);
            margin-top: 6px;
            padding-left: 16px;
        }
        
        .password-requirements li {
            list-style: none;
            margin-bottom: 2px;
        }
        
        .password-requirements li.valid {
            color: var(--accent);
        }
        
        .password-requirements li.valid::before {
            content: '✓ ';
        }
        
        .password-requirements li.invalid {
            color: var(--danger);
        }
        
        .password-requirements li.invalid::before {
            content: '✗ ';
        }
        
        .reset-link-box {
            background: var(--bg);
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
            word-break: break-all;
            font-size: 13px;
            border: 1px solid var(--border);
        }
        
        .reset-link-box a {
            color: var(--primary);
            text-decoration: none;
        }
        
        .reset-link-box a:hover {
            text-decoration: underline;
        }
        
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 44px;
            height: 44px;
            font-size: 18px;
            color: var(--text);
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
        }
        
        .theme-toggle:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: scale(1.05);
        }
        
        .admin-note {
            background: rgba(47, 128, 237, 0.05);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            color: var(--secondary-text);
        }
        
        .admin-note i {
            color: var(--primary);
        }
        
        .default-credentials {
            background: rgba(39, 174, 96, 0.05);
            border: 1px solid var(--accent);
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
            font-size: 13px;
        }
        
        .default-credentials strong {
            color: var(--text);
        }
        
        .default-credentials .label {
            color: var(--secondary-text);
        }
        
        @media (max-width: 480px) {
            .card {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>
    <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
        <i class="fas fa-<?php echo $theme === 'dark' ? 'sun' : 'moon'; ?>"></i>
    </button>
    
    <div class="container">
        <div class="card">
            <div class="logo">
                <h1>GlobalSCM</h1>
                <span>Supply Chain Management</span>
            </div>
            
            <?php if ($step === 'request'): ?>
                <div class="icon-wrapper">
                    <i class="fas fa-key"></i>
                </div>
                <div class="title">
                    <h2>Forgot Password?</h2>
                </div>
                <p class="subtitle">
                    Enter your email address to reset your password.<br>
                    <span class="highlight">Note: Only administrators can reset passwords.</span>
                </p>
                
                <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
                <?php if (isset($showResetLink) && isset($resetLink)): ?>
                <div class="reset-link-box">
                    <strong>Demo Reset Link:</strong><br>
                    <a href="<?php echo $resetLink; ?>" target="_blank"><?php echo $resetLink; ?></a>
                </div>
                <?php endif; ?>
                <div class="back-link" style="margin-top: 16px;">
                    <a href="login.php">← Back to Login</a>
                </div>
                <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label><i class="fas fa-envelope" style="color: var(--primary);"></i> Email Address</label>
                        <input type="email" name="email" placeholder="Enter your email" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>
                </form>
                
                <div class="admin-note">
                    <i class="fas fa-info-circle"></i> 
                    Only administrators can reset passwords. If you need assistance accessing your account, please contact your system administrator.
                </div>
                
                <div class="back-link">
                    <a href="login.php">← Back to Login</a>
                </div>
                <?php endif; ?>
                
            <?php elseif ($step === 'reset'): ?>
                <div class="icon-wrapper" style="color: var(--accent); background: rgba(39, 174, 96, 0.1);">
                    <i class="fas fa-lock"></i>
                </div>
                <div class="title">
                    <h2>Reset Password</h2>
                </div>
                <p class="subtitle">
                    Create a new password for your administrator account.
                </p>
                
                <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
                <div class="back-link" style="margin-top: 16px;">
                    <a href="login.php">← Back to Login</a>
                </div>
                <?php elseif ($showResetForm): ?>
                <form method="POST" id="resetForm">
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="password" id="password" required 
                               placeholder="Enter new password" minlength="8">
                        <ul class="password-requirements" id="passwordRequirements">
                            <li id="req-length">At least 8 characters</li>
                            <li id="req-upper">At least one uppercase letter</li>
                            <li id="req-lower">At least one lowercase letter</li>
                            <li id="req-number">At least one number</li>
                        </ul>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirmPassword" required 
                               placeholder="Confirm new password">
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i> Reset Password
                    </button>
                </form>
                
                <div class="admin-note" style="margin-top: 16px;">
                    <i class="fas fa-shield-alt"></i> 
                    This reset link is valid for 1 hour. For security, this link can only be used once.
                </div>
                
                <div class="back-link">
                    <a href="login.php">← Back to Login</a>
                </div>
                <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error ?: 'Invalid or expired reset token. Please request a new password reset.'; ?>
                </div>
                <div class="back-link">
                    <a href="forgot-password.php">Request New Reset</a> &bull;
                    <a href="login.php">Login</a>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="icon-wrapper" style="color: var(--danger); background: rgba(220, 38, 38, 0.1);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="title">
                    <h2>Invalid Request</h2>
                </div>
                <p class="subtitle">
                    The password reset link is invalid or has expired.
                </p>
                <div class="back-link" style="margin-top: 16px;">
                    <a href="forgot-password.php">Try Again</a> &bull;
                    <a href="login.php">Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        document.getElementById('themeToggle').addEventListener('click', function() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            fetch('/api/settings.php?action=theme', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'theme=' + newTheme
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    html.setAttribute('data-theme', newTheme);
                    this.innerHTML = '<i class="fas fa-' + (newTheme === 'dark' ? 'sun' : 'moon') + '"></i>';
                }
            })
            .catch(error => {
                html.setAttribute('data-theme', newTheme);
                this.innerHTML = '<i class="fas fa-' + (newTheme === 'dark' ? 'sun' : 'moon') + '"></i>';
            });
        });
        
        // Password validation
        document.addEventListener('DOMContentLoaded', function() {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirmPassword');
            
            if (password) {
                password.addEventListener('input', function() {
                    const value = this.value;
                    
                    const reqLength = document.getElementById('req-length');
                    const reqUpper = document.getElementById('req-upper');
                    const reqLower = document.getElementById('req-lower');
                    const reqNumber = document.getElementById('req-number');
                    
                    if (value.length >= 8) {
                        reqLength.className = 'valid';
                    } else {
                        reqLength.className = 'invalid';
                    }
                    
                    if (/[A-Z]/.test(value)) {
                        reqUpper.className = 'valid';
                    } else {
                        reqUpper.className = 'invalid';
                    }
                    
                    if (/[a-z]/.test(value)) {
                        reqLower.className = 'valid';
                    } else {
                        reqLower.className = 'invalid';
                    }
                    
                    if (/[0-9]/.test(value)) {
                        reqNumber.className = 'valid';
                    } else {
                        reqNumber.className = 'invalid';
                    }
                });
            }
            
            if (confirmPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (this.value && password.value !== this.value) {
                        this.style.borderColor = '#DC2626';
                    } else {
                        this.style.borderColor = '';
                    }
                });
            }
        });
    </script>
</body>
</html>