<?php
// admin/security.php
// Travels and Tours - Logistics 1: Account Security & 2FA Management Module
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Services/TotpService.php';

// Accessible by all authenticated staff roles
requireAuth();

use App\Services\TotpService;

$totpService = new TotpService();
$userId = (int)($_SESSION['user_id'] ?? 0);
$theme = function_exists('getTheme') ? getTheme() : 'light';

// Fetch current user record
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header('Location: ../login.php');
    exit();
}

// ── Handle AJAX Requests ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCSRFToken($csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page.']);
        exit();
    }

    // 1. Provision 2FA (Sudo Password Required)
    if ($action === 'provision') {
        $password = (string)($_POST['sudo_password'] ?? '');
        if (!$totpService->verifySudoPassword($pdo, $userId, $password)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Password confirmation failed. Please check your current password.']);
            exit();
        }

        $secret = $totpService->generateSecret(16);
        $provisioningUri = $totpService->getProvisioningUri((string)$currentUser['email'], $secret);
        $recoveryCodes = $totpService->generateRecoveryCodes();

        $_SESSION['pending_2fa_setup'] = [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
            'user_id' => $userId,
            'timestamp' => time()
        ];

        echo json_encode([
            'success' => true,
            'secret' => $secret,
            'provisioning_uri' => $provisioningUri,
            'recovery_codes' => $recoveryCodes,
            'issuer' => TotpService::ISSUER,
            'email' => $currentUser['email'],
        ]);
        exit();
    }

    // 2. Confirm & Activate 2FA
    if ($action === 'confirm') {
        $identifier = 'user_' . $userId;
        if ($totpService->isRateLimited($pdo, $identifier)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many failed verification attempts. Please wait 60 seconds before trying again.']);
            exit();
        }

        $pending = $_SESSION['pending_2fa_setup'] ?? null;
        $secret = (string)($_POST['secret'] ?? ($pending['secret'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $recoveryCodes = $_POST['recovery_codes'] ?? ($pending['recovery_codes'] ?? []);

        if (empty($secret) || empty($code) || empty($recoveryCodes) || !is_array($recoveryCodes)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Missing setup verification parameters.']);
            exit();
        }

        $result = $totpService->confirmSetup($pdo, $userId, $secret, $code, $recoveryCodes);

        if (!$result['success']) {
            $totpService->recordFailedAttempt($pdo, $identifier, $userId);
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $result['error']]);
            exit();
        }

        $totpService->clearRateLimits($pdo, $identifier);
        unset($_SESSION['pending_2fa_setup']);

        echo json_encode([
            'success' => true,
            'message' => 'Google Authenticator 2FA has been successfully enrolled and activated for your account!',
            'confirmed_at' => $result['confirmed_at']
        ]);
        exit();
    }

    // 3. Disable 2FA (Sudo Password Required)
    if ($action === 'disable') {
        $password = (string)($_POST['sudo_password'] ?? '');
        if (!$totpService->verifySudoPassword($pdo, $userId, $password)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Password confirmation failed. Please check your current password.']);
            exit();
        }

        $totpService->disable($pdo, $userId);
        echo json_encode([
            'success' => true,
            'message' => 'Two-factor authentication has been disabled for your account.'
        ]);
        exit();
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit();
}

// ── GET Request: Render Account Security Panel ────────────────────────────────
$securityStatus = $totpService->getUserSecurityStatus($pdo, $userId);

// Fetch recent security audit events for this user
$auditStmt = $pdo->prepare("SELECT action, description, ip_address, created_at FROM audit_logs 
    WHERE user_id = ? AND module IN ('security', 'auth') 
    ORDER BY id DESC LIMIT 5");
$auditStmt->execute([$userId]);
$recentSecurityLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Security & 2FA — Travels & Tours Logistics 1</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary-blue: #1A6FD4;
            --secondary-cyan: #00C2FF;
            --accent-green: #10B981;
            --danger-rose: #EF4444;
            --warning-amber: #F59E0B;
        }

        .font-mono-code {
            font-family: 'JetBrains Mono', monospace;
        }

        .sec-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        }

        .sec-card:hover {
            box-shadow: 0 8px 30px rgba(0,0,0,0.07);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .status-pill.active {
            background: rgba(16, 185, 129, 0.12);
            color: #10B981;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }

        .status-pill.inactive {
            background: rgba(245, 158, 11, 0.12);
            color: #F59E0B;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 10px currentColor;
            animation: pulseAnim 2s infinite;
        }

        @keyframes pulseAnim {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        .sec-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
        }

        .sec-btn-primary {
            background: linear-gradient(135deg, #1A6FD4, #00C2FF);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(26, 111, 212, 0.35);
        }

        .sec-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(26, 111, 212, 0.45);
        }

        .sec-btn-danger {
            background: rgba(239, 68, 68, 0.12);
            color: #EF4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .sec-btn-danger:hover {
            background: #EF4444;
            color: #ffffff;
        }

        .sec-btn-outline {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .sec-btn-outline:hover {
            background: rgba(255,255,255,0.05);
            border-color: var(--primary);
        }

        /* Modal Backdrop */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 22, 40, 0.75);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }

        .modal-dialog {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            width: 100%;
            max-width: 580px;
            padding: 28px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
            position: relative;
            animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .code-chip {
            background: rgba(0,0,0,0.15);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
            letter-spacing: 0.05em;
            user-select: all;
        }

        [data-theme="dark"] .code-chip {
            background: rgba(15, 23, 42, 0.8);
            border-color: rgba(51, 65, 85, 0.8);
            color: #38BDF8;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar Navigation -->
        <?php include __DIR__ . '/partials/sidebar.php'; ?>

        <!-- Main Content View -->
        <main class="main-content">
            <!-- Top Bar Header -->
            <div class="top-bar">
                <div class="page-title">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 20px; color: #1A6FD4;"><i class="fas fa-shield-alt"></i></span>
                        <div>
                            <h1 style="margin: 0; font-size: 22px; font-weight: 700;">Account Security & 2FA</h1>
                            <p style="margin: 2px 0 0; font-size: 12px; color: var(--secondary-text);">
                                Self-service Google Authenticator (RFC 6238 TOTP) protection for Travels & Tours — Logistics 1
                            </p>
                        </div>
                    </div>
                </div>
                <div class="top-bar-actions" style="display: flex; align-items: center; gap: 12px;">
                    <?php include __DIR__ . '/partials/headbar_actions.php'; ?>
                </div>
            </div>

            <!-- Page Body Container -->
            <div style="padding: 24px; max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 24px;">

                <!-- Status Banner Card -->
                <div class="sec-card" style="background: linear-gradient(135deg, rgba(26, 111, 212, 0.05), rgba(0, 194, 255, 0.08)); border-color: rgba(26, 111, 212, 0.2); position: relative; overflow: hidden;">
                    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px;">
                        <div style="max-width: 650px;">
                            <div style="margin-bottom: 12px;">
                                <?php if ($securityStatus['is_enabled']): ?>
                                    <div class="status-pill active">
                                        <span class="pulse-dot"></span>
                                        <span>2FA Active &amp; Protected</span>
                                    </div>
                                <?php else: ?>
                                    <div class="status-pill inactive">
                                        <span class="pulse-dot"></span>
                                        <span>2FA Inactive / Not Configured</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h2 style="font-size: 20px; font-weight: 800; margin: 0 0 8px var(--text);">
                                <?php if ($securityStatus['is_enabled']): ?>
                                    Your Staff Account is Secured with Google Authenticator
                                <?php else: ?>
                                    Two-Factor Authentication is Not Yet Enabled
                                <?php endif; ?>
                            </h2>
                            <p style="margin: 0; font-size: 13px; color: var(--secondary-text); line-height: 1.6;">
                                <?php if ($securityStatus['is_enabled']): ?>
                                    Every sign-in to the Travels and Tours Logistics Command Center requires your master password plus a rotating 6-digit TOTP verification token.
                                <?php else: ?>
                                    Enforce hardware-bound 6-digit TOTP rotation on your phone to prevent unauthorized access across Super Admin, Dispatch, Logistics, Fleet, and Facility management operations.
                                <?php endif; ?>
                            </p>
                        </div>

                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <?php if ($securityStatus['is_enabled']): ?>
                                <button type="button" onclick="openDisableModal()" class="sec-btn sec-btn-danger">
                                    <i class="fas fa-lock-open"></i> Disable 2FA
                                </button>
                                <button type="button" onclick="startSetup()" class="sec-btn sec-btn-outline">
                                    <i class="fas fa-sync-alt"></i> Reconfigure Authenticator
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="startSetup()" class="sec-btn sec-btn-primary">
                                    <i class="fas fa-qrcode"></i> Enable Google Authenticator
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 3-Column Specifications Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">

                    <!-- 1. Staff Identity Card -->
                    <div class="sec-card">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(26, 111, 212, 0.12); color: #1A6FD4; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700;">Account Credentials</h3>
                                <span style="font-size: 11px; color: var(--secondary-text);">Identity assigned on system</span>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid var(--border);">
                                <span style="color: var(--secondary-text);">Staff Name</span>
                                <strong style="color: var(--text);"><?php echo htmlspecialchars($currentUser['full_name']); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid var(--border);">
                                <span style="color: var(--secondary-text);">Registered Work Email</span>
                                <strong class="font-mono-code" style="color: #1A6FD4;"><?php echo htmlspecialchars($currentUser['email']); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid var(--border);">
                                <span style="color: var(--secondary-text);">Operational Role</span>
                                <span style="background: rgba(0,0,0,0.06); padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $currentUser['role'])); ?>
                                </span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--secondary-text);">Enrolled Since</span>
                                <span style="color: var(--text);">
                                    <?php echo !empty($securityStatus['confirmed_at']) ? date('M d, Y h:i A', strtotime($securityStatus['confirmed_at'])) : '—'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Emergency Recovery Codes Vault -->
                    <div class="sec-card">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(99, 102, 241, 0.12); color: #6366F1; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fas fa-key"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700;">Emergency Access Vault</h3>
                                <span style="font-size: 11px; color: var(--secondary-text);">Single-use fallback login keys</span>
                            </div>
                        </div>

                        <p style="margin: 0 0 16px; font-size: 12px; color: var(--secondary-text); line-height: 1.5;">
                            In the event of phone loss or authenticator reset, 8 emergency recovery codes (<code class="font-mono-code">TRVL-XXXX-XX</code>) grant one-time sign-in access.
                        </p>

                        <div style="background: rgba(0,0,0,0.04); border: 1px solid var(--border); border-radius: 12px; padding: 14px; display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--secondary-text);">Unused Access Codes</div>
                                <div style="font-size: 24px; font-weight: 800; color: var(--text);">
                                    <?php echo (int)($securityStatus['remaining_recovery_codes'] ?? 0); ?>
                                    <span style="font-size: 13px; font-weight: 400; color: var(--secondary-text);">/ 8 codes</span>
                                </div>
                            </div>
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(99, 102, 241, 0.15); color: #6366F1; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                <i class="fas fa-vault"></i>
                            </div>
                        </div>

                        <?php if ($securityStatus['is_enabled'] && $securityStatus['remaining_recovery_codes'] <= 2): ?>
                            <div style="margin-top: 12px; font-size: 11px; color: #DC2626; background: rgba(220, 38, 38, 0.08); padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(220, 38, 38, 0.2);">
                                <i class="fas fa-exclamation-triangle"></i> Warning: Low recovery codes remaining. Reconfigure 2FA to replenish a fresh set of 8 codes.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 3. Security Standard Specifications -->
                    <div class="sec-card">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); color: #10B981; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                <i class="fas fa-fingerprint"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700;">RFC 6238 Protocol Specs</h3>
                                <span style="font-size: 11px; color: var(--secondary-text);">Strict logistics compliance</span>
                            </div>
                        </div>

                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 12px; display: flex; flex-direction: column; gap: 10px; color: var(--secondary-text);">
                            <li style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-check-circle" style="color: #10B981; font-size: 13px;"></i>
                                <span><strong>Algorithm:</strong> HMAC-SHA1 RFC 6238 (6 Digits)</span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-check-circle" style="color: #10B981; font-size: 13px;"></i>
                                <span><strong>Rotation Period:</strong> 30s with ±1 Window Drift Tolerance</span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-check-circle" style="color: #10B981; font-size: 13px;"></i>
                                <span><strong>Encryption:</strong> AES-256-CBC at rest with HMAC SHA-256</span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-check-circle" style="color: #10B981; font-size: 13px;"></i>
                                <span><strong>Brute-Force Guard:</strong> Max 5 attempts / 60 seconds</span>
                            </li>
                            <li style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-check-circle" style="color: #10B981; font-size: 13px;"></i>
                                <span><strong>Issuer:</strong> Travels &amp; Tours - Logistics 1</span>
                            </li>
                        </ul>
                    </div>

                </div>

                <!-- Recent Security Audit Trail for Current Account -->
                <div class="sec-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-history" style="color: #1A6FD4;"></i>
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Recent Account Security Events</h3>
                        </div>
                        <span style="font-size: 11px; color: var(--secondary-text);">Audited in System Trail</span>
                    </div>

                    <?php if (empty($recentSecurityLogs)): ?>
                        <div style="padding: 24px; text-align: center; color: var(--secondary-text); font-size: 13px;">
                            No recent security audit logs recorded for your account.
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 12px; text-align: left;">
                                <thead>
                                    <tr style="border-bottom: 1px solid var(--border); color: var(--secondary-text);">
                                        <th style="padding: 8px 12px; font-weight: 600;">Action</th>
                                        <th style="padding: 8px 12px; font-weight: 600;">Description</th>
                                        <th style="padding: 8px 12px; font-weight: 600;">IP Address</th>
                                        <th style="padding: 8px 12px; font-weight: 600;">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentSecurityLogs as $log): ?>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <td style="padding: 10px 12px; font-weight: 600; color: #1A6FD4;">
                                                <code class="font-mono-code"><?php echo htmlspecialchars($log['action']); ?></code>
                                            </td>
                                            <td style="padding: 10px 12px; color: var(--text);">
                                                <?php echo htmlspecialchars($log['description']); ?>
                                            </td>
                                            <td style="padding: 10px 12px; color: var(--secondary-text); font-family: monospace;">
                                                <?php echo htmlspecialchars($log['ip_address'] ?? '127.0.0.1'); ?>
                                            </td>
                                            <td style="padding: 10px 12px; color: var(--secondary-text);">
                                                <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['created_at']))); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <!-- Sudo Re-Authentication Modal -->
    <div id="sudoModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(245, 158, 11, 0.15); color: #F59E0B; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Sudo Re-Authentication</h3>
                        <span style="font-size: 11px; color: var(--secondary-text);">Identity confirmation required</span>
                    </div>
                </div>
                <button type="button" onclick="closeModal('sudoModal')" style="background: none; border: none; font-size: 16px; color: var(--secondary-text); cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p style="margin: 0 0 16px; font-size: 13px; color: var(--secondary-text); line-height: 1.5;">
                For your security, please confirm your current account password to view the Google Authenticator QR setup key.
            </p>

            <form id="sudoForm" onsubmit="handleSudoSubmit(event)">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text);">Account Password</label>
                    <input type="password" id="sudoPassword" required placeholder="Enter your current password"
                        style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg); color: var(--text); font-size: 13px; outline: none;">
                </div>

                <div id="sudoError" style="display: none; margin-bottom: 16px; padding: 10px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; font-size: 12px;"></div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeModal('sudoModal')" class="sec-btn sec-btn-outline">Cancel</button>
                    <button type="submit" id="sudoSubmitBtn" class="sec-btn sec-btn-primary">
                        <i class="fas fa-unlock"></i> Verify &amp; Proceed
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2FA Provisioning Setup Modal -->
    <div id="setupModal" class="modal-overlay">
        <div class="modal-dialog" style="max-width: 650px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(26, 111, 212, 0.15); color: #1A6FD4; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-qrcode text-lg"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 17px; font-weight: 700;">Setup Google Authenticator</h3>
                        <span style="font-size: 11px; color: var(--secondary-text);">Travels &amp; Tours - Logistics 1</span>
                    </div>
                </div>
                <button type="button" onclick="closeModal('setupModal')" style="background: none; border: none; font-size: 18px; color: var(--secondary-text); cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Step 1: Scan QR Code -->
            <div style="display: grid; grid-template-columns: 200px 1fr; gap: 20px; align-items: center; margin-bottom: 20px;">
                <div style="background: #ffffff; padding: 12px; border-radius: 12px; display: flex; justify-content: center; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <div id="qrcodeCanvas"></div>
                </div>
                <div style="font-size: 12px; color: var(--secondary-text); display: flex; flex-direction: column; gap: 10px;">
                    <div style="font-weight: 700; color: #1A6FD4; text-transform: uppercase; font-size: 11px;">Step 1: Scan With Google Authenticator</div>
                    <p style="margin: 0; line-height: 1.5; color: var(--text);">
                        Open the <strong>Google Authenticator</strong> app on your smartphone, tap the <strong>"+"</strong> button, and point your camera at this QR code.
                    </p>
                    <div>
                        <span style="font-weight: 600; font-size: 11px;">Or enter code manually:</span>
                        <div style="display: flex; gap: 6px; margin-top: 4px;">
                            <input type="text" id="manualSecretInput" readonly class="font-mono-code" style="width: 100%; padding: 6px 10px; font-size: 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg); color: var(--text); font-weight: 700;">
                            <button type="button" onclick="copySecret()" title="Copy Key" class="sec-btn sec-btn-outline" style="padding: 6px 12px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Emergency Recovery Codes (8 single-use) -->
            <div style="background: rgba(0,0,0,0.04); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <div style="font-size: 12px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-key" style="color: #6366F1;"></i>
                        <span>Step 2: Save Emergency Recovery Codes (8 Codes)</span>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" onclick="downloadCodesTxt()" class="sec-btn sec-btn-outline" style="padding: 4px 10px; font-size: 11px;">
                            <i class="fas fa-download"></i> Download .txt
                        </button>
                        <button type="button" onclick="printCodes()" class="sec-btn sec-btn-outline" style="padding: 4px 10px; font-size: 11px;">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div id="recoveryCodesGrid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                    <!-- Populated via JavaScript -->
                </div>
                <div style="font-size: 11px; color: var(--secondary-text); margin-top: 8px;">
                    <i class="fas fa-info-circle" style="color: #1A6FD4;"></i> Each code can be used exactly once if you lose phone access.
                </div>
            </div>

            <!-- Step 3: Enter 6-digit Code to Confirm -->
            <form id="confirmSetupForm" onsubmit="handleConfirmSubmit(event)">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; margin-bottom: 6px; color: #1A6FD4; text-transform: uppercase;">
                        Step 3: Enter 6-Digit Authenticator Code
                    </label>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <input type="text" id="verifyTotpCode" maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                            class="font-mono-code" style="font-size: 22px; font-weight: 800; letter-spacing: 0.3em; text-align: center; width: 170px; padding: 10px; border-radius: 10px; border: 2px solid var(--primary); background: var(--bg); color: var(--text); outline: none;">
                        <span style="font-size: 12px; color: var(--secondary-text);">Enter rotating code currently shown in your app</span>
                    </div>
                </div>

                <div id="confirmError" style="display: none; margin-bottom: 16px; padding: 10px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; font-size: 12px;"></div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--border); padding-top: 16px;">
                    <button type="button" onclick="closeModal('setupModal')" class="sec-btn sec-btn-outline">Cancel</button>
                    <button type="submit" id="confirmSubmitBtn" class="sec-btn sec-btn-primary" style="background: linear-gradient(135deg, #10B981, #059669);">
                        <i class="fas fa-check-circle"></i> Verify &amp; Activate 2FA
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Disable 2FA Modal -->
    <div id="disableModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(239, 68, 68, 0.15); color: #EF4444; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Disable 2FA Protection</h3>
                        <span style="font-size: 11px; color: var(--secondary-text);">Sudo password confirmation</span>
                    </div>
                </div>
                <button type="button" onclick="closeModal('disableModal')" style="background: none; border: none; font-size: 16px; color: var(--secondary-text); cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p style="margin: 0 0 16px; font-size: 13px; color: var(--secondary-text); line-height: 1.5;">
                Disabling Google Authenticator will revoke two-factor protection and instantly invalidate all single-use emergency backup codes.
            </p>

            <form id="disableForm" onsubmit="handleDisableSubmit(event)">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text);">Account Password</label>
                    <input type="password" id="disablePassword" required placeholder="Enter password to confirm"
                        style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg); color: var(--text); font-size: 13px; outline: none;">
                </div>

                <div id="disableError" style="display: none; margin-bottom: 16px; padding: 10px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; font-size: 12px;"></div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeModal('disableModal')" class="sec-btn sec-btn-outline">Cancel</button>
                    <button type="submit" id="disableSubmitBtn" class="sec-btn sec-btn-danger" style="background: #EF4444; color: #ffffff;">
                        <i class="fas fa-trash-alt"></i> Disable 2FA
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Client-Side Interactive Logic -->
    <script>
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
        const USER_EMAIL = <?php echo json_encode($currentUser['email']); ?>;
        let currentSecret = '';
        let currentRecoveryCodes = [];

        function startSetup() {
            document.getElementById('sudoPassword').value = '';
            document.getElementById('sudoError').style.display = 'none';
            openModal('sudoModal');
        }

        function openDisableModal() {
            document.getElementById('disablePassword').value = '';
            document.getElementById('disableError').style.display = 'none';
            openModal('disableModal');
        }

        function openModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'flex';
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }

        // Handle Sudo Re-Authentication
        async function handleSudoSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('sudoSubmitBtn');
            const err = document.getElementById('sudoError');
            const password = document.getElementById('sudoPassword').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            err.style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('action', 'provision');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('sudo_password', password);

                const res = await fetch('security.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Sudo authorization failed.');
                }

                currentSecret = data.secret;
                currentRecoveryCodes = data.recovery_codes || [];

                closeModal('sudoModal');
                renderSetupWizard(data.provisioning_uri, data.secret, data.recovery_codes);
            } catch (error) {
                err.textContent = error.message;
                err.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-unlock"></i> Verify & Proceed';
            }
        }

        function renderSetupWizard(uri, secret, codes) {
            // Render QR Code
            const qrContainer = document.getElementById('qrcodeCanvas');
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: uri,
                width: 176,
                height: 176,
                colorDark: "#0A1628",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });

            document.getElementById('manualSecretInput').value = secret;

            // Render 8 Recovery Codes
            const grid = document.getElementById('recoveryCodesGrid');
            grid.innerHTML = '';
            codes.forEach((code) => {
                const div = document.createElement('div');
                div.className = 'code-chip font-mono-code';
                div.textContent = code;
                grid.appendChild(div);
            });

            document.getElementById('verifyTotpCode').value = '';
            document.getElementById('confirmError').style.display = 'none';
            openModal('setupModal');
        }

        // Auto-restrict TOTP code input to numeric digits only
        document.addEventListener('DOMContentLoaded', () => {
            const totpInput = document.getElementById('verifyTotpCode');
            if (totpInput) {
                totpInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '').slice(0, 6);
                });
            }
        });

        // Handle 6-Digit Confirmation
        async function handleConfirmSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('confirmSubmitBtn');
            const err = document.getElementById('confirmError');
            const codeRaw = document.getElementById('verifyTotpCode').value;
            const code = codeRaw.replace(/\D/g, '').trim();

            if (code.length !== 6) {
                err.textContent = 'Please enter the 6-digit verification code from your Google Authenticator app.';
                err.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Activating...';
            err.style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('action', 'confirm');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('secret', currentSecret);
                formData.append('code', code);
                currentRecoveryCodes.forEach(c => formData.append('recovery_codes[]', c));

                const res = await fetch('security.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Invalid authenticator code.');
                }

                alert('Success! Google Authenticator 2FA is now active for your account.');
                window.location.reload();
            } catch (error) {
                err.textContent = error.message;
                err.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Verify & Activate 2FA';
            }
        }

        // Handle Disable 2FA
        async function handleDisableSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('disableSubmitBtn');
            const err = document.getElementById('disableError');
            const password = document.getElementById('disablePassword').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            err.style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('action', 'disable');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('sudo_password', password);

                const res = await fetch('security.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Password confirmation failed.');
                }

                alert('Two-factor authentication has been disabled.');
                window.location.reload();
            } catch (error) {
                err.textContent = error.message;
                err.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> Disable 2FA';
            }
        }

        function copySecret() {
            const input = document.getElementById('manualSecretInput');
            navigator.clipboard.writeText(input.value);
            alert('Secret key copied to clipboard!');
        }

        function downloadCodesTxt() {
            if (!currentRecoveryCodes.length) return;
            let text = "========================================================\n" +
                       "   TRAVELS & TOURS - LOGISTICS 1\n" +
                       "   EMERGENCY BACKUP RECOVERY CODES\n" +
                       "========================================================\n\n" +
                       "Account: " + USER_EMAIL + "\n" +
                       "Date: " + new Date().toISOString() + "\n\n" +
                       "KEEP THESE CODES SECURE. Each code can be used ONCE.\n" +
                       "--------------------------------------------------------\n";
            currentRecoveryCodes.forEach((c, i) => {
                text += `[${i+1}] ${c}\n`;
            });
            text += "--------------------------------------------------------\n";

            const blob = new Blob([text], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `travels-logistics-2fa-recovery-codes-${Date.now()}.txt`;
            a.click();
            URL.revokeObjectURL(url);
        }

        function printCodes() {
            const w = window.open('', '_blank');
            let html = `<html><head><title>Print Recovery Codes</title><style>body{font-family:monospace;padding:30px;line-height:1.6;}h2{margin-bottom:5px;}</style></head><body>`;
            html += `<h2>Travels & Tours - Logistics 1</h2>`;
            html += `<p>Emergency Backup Recovery Codes — Account: ${USER_EMAIL}</p><hr><ol>`;
            currentRecoveryCodes.forEach(c => {
                html += `<li><strong>${c}</strong></li>`;
            });
            html += `</ol><hr><p>Keep these codes secure. Each code is single-use only.</p></body></html>`;
            w.document.write(html);
            w.document.close();
            w.focus();
            w.print();
        }
    </script>
</body>
</html>
