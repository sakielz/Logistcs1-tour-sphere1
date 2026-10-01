<?php
// login.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/function.php';

if (!function_exists('getTheme')) {
    function getTheme() {
        return 'light';
    }
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
if (!defined('COLOR_DARK_BG')) define('COLOR_DARK_BG', '#0F172A');
if (!defined('COLOR_DARK_CARD')) define('COLOR_DARK_CARD', '#111827');
if (!defined('COLOR_DARK_TEXT')) define('COLOR_DARK_TEXT', '#E5E7EB');
if (!defined('COLOR_DARK_SECONDARY_TEXT')) define('COLOR_DARK_SECONDARY_TEXT', '#94A3B8');
if (!defined('COLOR_DARK_BORDER')) define('COLOR_DARK_BORDER', '#1F2937');

// Get theme setting
$theme = function_exists('getTheme') ? getTheme() : 'light';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST['remember']) ? true : false;
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        try {
            $loginIdentifier = trim($email);
            $sql = "SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(:login)) OR LOWER(TRIM(username)) = LOWER(TRIM(:login)) LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':login' => $loginIdentifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'No account was found with that email address or username.';
                logAudit(null, 'login_failed', 'auth', "Login failed - account not found: $email");
            } elseif (!(bool)$user['is_active']) {
                $error = 'This account is inactive. Please contact your Administrator.';
                logAudit(null, 'login_failed', 'auth', "Login failed - inactive account: $email");
            } elseif ((bool)$user['is_archived']) {
                $error = 'This account is archived. Please contact your Administrator.';
                logAudit(null, 'login_failed', 'auth', "Login failed - archived account: $email");
            } elseif (empty($user['password'])) {
                $error = 'This account does not have a valid password. Please reset the account password.';
                logAudit(null, 'login_failed', 'auth', "Login failed - empty password: $email");
            } elseif (!password_verify($password, $user['password'])) {
                $error = 'The password entered is incorrect.';
                logAudit(null, 'login_failed', 'auth', "Login failed - incorrect password: $email");
            } else {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }

                $_SESSION['user_id']     = $user['id'];
                $_SESSION['username']    = $user['username'];
                $_SESSION['role']        = $user['role'];
                $_SESSION['full_name']   = $user['full_name'];
                $_SESSION['email']       = $user['email'];
                $_SESSION['last_activity'] = time();  // seed inactivity timer

                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $rehashStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $rehashStmt->execute([$newHash, $user['id']]);
                }

                $stmt = $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?");
                $stmt->execute([$user['id']]);

                logAudit($user['id'], 'login', 'auth', 'User logged in');

                header('Location: admin/dashboard.php');
                exit();
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}
?>
<?php
// Show session timeout notice
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $timeoutMessage = 'Your session expired due to 2 minutes of inactivity. Please log in again.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GlobalSCM</title>
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
        
        .login-container {
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }
        
        .login-card {
            background: var(--card);
            border-radius: 20px;
            padding: 48px 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 8px;
        }
        
        .logo h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.5px;
        }
        
        .logo .subtitle {
            font-size: 12px;
            color: var(--secondary-text);
            letter-spacing: 2px;
            text-transform: uppercase;
            display: block;
        }
        
        .welcome-text {
            text-align: center;
            margin: 30px 0 25px;
        }
        
        .lock-icon {
            width: 60px;
            height: 60px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: white;
            font-size: 24px;
        }
        
        .welcome-text h2 {
            font-size: 22px;
            font-weight: 600;
            color: var(--text);
        }
        
        .welcome-text p {
            color: var(--secondary-text);
            font-size: 14px;
            margin-top: 4px;
        }
        
        .error-message {
            background: #FEE2E2;
            color: #DC2626;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            display: <?php echo isset($error) ? 'flex' : 'none'; ?>;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #DC2626;
        }
        
        .form-group {
            margin-bottom: 18px;
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
            border-radius: 12px;
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
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .form-options label {
            font-size: 13px;
            color: var(--secondary-text);
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .form-options label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        
        .form-options a {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .form-options a:hover {
            text-decoration: underline;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
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
        
        .btn-login:hover {
            background: #2563EB;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(47, 128, 237, 0.3);
        }
        
        .divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: var(--border);
        }
        
        .divider span {
            background: var(--card);
            padding: 0 15px;
            position: relative;
            font-size: 13px;
            color: var(--secondary-text);
        }
        
        .social-login {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .social-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: var(--bg);
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: var(--text);
        }
        
        .social-btn:hover {
            border-color: var(--primary);
            background: var(--card);
        }
        
        .signup-link {
            text-align: center;
            margin-top: 25px;
            font-size: 14px;
            color: var(--secondary-text);
        }
        
        .signup-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .default-credentials {
            background: rgba(39, 174, 96, 0.08);
            border: 1px solid var(--accent);
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 16px;
            font-size: 12px;
            text-align: center;
        }
        
        .default-credentials strong {
            color: var(--text);
        }
        
        .default-credentials .label {
            color: var(--secondary-text);
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
        
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }
            
            .social-login {
                flex-direction: column;
            }
            
            .form-options {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
        <i class="fas fa-<?php echo $theme === 'dark' ? 'sun' : 'moon'; ?>"></i>
    </button>
    
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <h1>GlobalSCM</h1>
                <span class="subtitle">Supply Chain Management</span>
            </div>
            
            <div class="welcome-text">
                <div class="lock-icon"><i class="fas fa-warehouse"></i></div>
                <h2>Welcome to GlobalSCM</h2>
                <p>Logistics &amp; Data Coordination Portal</p>
            </div>
            
            <?php if (isset($timeoutMessage)): ?>
            <div class="error-message" style="background:#FEF3C7;color:#92400E;border-left-color:#D97706;">
                <i class="fas fa-clock"></i>
                <?php echo htmlspecialchars($timeoutMessage); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
            <?php endif; ?>
            

            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-envelope" style="color: var(--primary);"></i> Email Address</label>
                    <input type="email" name="email" placeholder="admin@globalscm.com" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-lock" style="color: var(--primary);"></i> Password</label>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>
                
                <div class="form-options">
                    <label>
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <a href="forgot-password.php">Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            
            <div class="default-credentials">
                <strong>Default Admin Credentials:</strong><br>
                <span class="label">Email:</span> admin@globalscm.com &nbsp;|&nbsp; 
                <span class="label">Password:</span> admin@08
            </div>
            
            <div class="divider">
                <span>Or log in with</span>
            </div>
            
            <div class="social-login">
                <button class="social-btn" onclick="showNotification('Google SSO coming soon!', 'info')">
                    <i class="fab fa-google" style="color: #DB4437;"></i> Google
                </button>
                <button class="social-btn" onclick="showNotification('SSO coming soon!', 'info')">
                    <i class="fas fa-id-card" style="color: var(--primary);"></i> SSO
                </button>
            </div>
            
            <div class="signup-link">
                Don't have an account? <a href="#">Contact your Administrator</a>
            </div>
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
        
        function showNotification(message, type) {
            let container = document.getElementById('notificationContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'notificationContainer';
                container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;max-width:400px;width:100%;';
                document.body.appendChild(container);
            }
            
            const colors = {
                info: { bg: '#DBEAFE', text: '#1E40AF', icon: 'ℹ️' }
            };
            const color = colors[type] || colors.info;
            
            const notification = document.createElement('div');
            notification.style.cssText = 
                'background:' + color.bg + ';' +
                'color:' + color.text + ';' +
                'padding:15px 20px;' +
                'border-radius:10px;' +
                'margin-bottom:10px;' +
                'box-shadow:0 4px 12px rgba(0,0,0,0.1);' +
                'font-family:Poppins,sans-serif;' +
                'font-size:14px;' +
                'display:flex;' +
                'align-items:center;' +
                'gap:12px;' +
                'animation:slideIn 0.3s ease;' +
                'border-left:4px solid ' + color.text + ';';
            
            notification.innerHTML = 
                '<span style="font-size:20px;">' + color.icon + '</span>' +
                '<span style="flex:1;">' + message + '</span>' +
                '<button onclick="this.parentElement.remove()" style="background:none;border:none;font-size:18px;cursor:pointer;color:' + color.text + ';">×</button>';
            
            container.appendChild(notification);
            
            setTimeout(function() {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(function() {
                        if (notification.parentElement) {
                            notification.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }
        
        const style = document.createElement('style');
        style.textContent = 
            '@keyframes slideIn {' +
                'from { transform: translateX(100%); opacity: 0; }' +
                'to { transform: translateX(0); opacity: 1; }' +
            '}' +
            '@keyframes slideOut {' +
                'from { transform: translateX(0); opacity: 1; }' +
                'to { transform: translateX(100%); opacity: 0; }' +
            '}';
        document.head.appendChild(style);
    </script>
</body>
</html>