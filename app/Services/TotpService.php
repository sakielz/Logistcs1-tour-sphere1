<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * TotpService
 *
 * Enterprise RFC 6238 TOTP Two-Factor Authentication Service for Travels & Tours - Logistics 1.
 * Provides:
 * - Pure PHP 8 Base32 and HMAC-SHA1 RFC 6238 TOTP engine (30s rotation, ±1 window drift tolerance).
 * - System branding & exact Google Authenticator provisioning URI formatting.
 * - AES-256 encrypted secret storage at rest (compatible with Laravel's Crypt format).
 * - 8 single-use emergency recovery codes (TRVL-XXXX-XX) hashed with bcrypt.
 * - Brute-force throttling (max 5 failed attempts per 60s per account/IP).
 * - Full audit trail logging for security compliance.
 */
class TotpService
{
    public const ISSUER = 'Travels & Tours - Logistics 1';
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const DRIFT_WINDOW = 1; // ±1 slice (30s before or after)
    public const MAX_FAILED_ATTEMPTS = 5;
    public const RATE_LIMIT_SECONDS = 60;
    public const RECOVERY_CODE_COUNT = 8;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure random Base32 secret string.
     */
    public function generateSecret(int $length = 16): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $secret = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[ord($bytes[$i]) % 32];
        }
        return $secret;
    }

    /**
     * Decode a Base32 encoded string into binary.
     */
    public function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $alphabet = self::BASE32_ALPHABET;
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($b32); $i++) {
            $val = strpos($alphabet, $b32[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }

    /**
     * Calculate 6-digit TOTP code for a specific time slice.
     */
    public function calculateCode(string $secret, int $timeSlice): string
    {
        $binarySecret = $this->base32Decode($secret);
        // Pack into 8-byte big-endian int64
        $timeBytes = pack('N*', 0, $timeSlice);
        $hmac = hash_hmac('sha1', $timeBytes, $binarySecret, true);
        $offset = ord($hmac[19]) & 0x0F;
        $hashPart = substr($hmac, $offset, 4);
        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;
        $code = (string)($value % (10 ** self::DIGITS));
        return str_pad($code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Get network time offset (local_time - network_time) in seconds to accommodate server clock drift.
     */
    public function getNetworkTimeOffset(): int
    {
        static $cachedOffset = null;
        if ($cachedOffset !== null) {
            return $cachedOffset;
        }

        if (isset($_SESSION['totp_network_time_offset'])) {
            $cachedOffset = (int)$_SESSION['totp_network_time_offset'];
            return $cachedOffset;
        }

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => 2,
                    'ignore_errors' => true
                ]
            ]);
            $headers = @get_headers('https://www.google.com', true, $ctx);
            if ($headers && isset($headers['Date'])) {
                $remoteDate = is_array($headers['Date']) ? end($headers['Date']) : $headers['Date'];
                $remoteTime = strtotime((string)$remoteDate);
                if ($remoteTime > 0) {
                    $offset = time() - $remoteTime;
                    $_SESSION['totp_network_time_offset'] = $offset;
                    $cachedOffset = $offset;
                    return $offset;
                }
            }
        } catch (Throwable $e) {}

        $cachedOffset = 0;
        return 0;
    }

    /**
     * Verify a 6-digit TOTP code with strict ±1 window drift tolerance,
     * with automatic system clock drift compensation.
     */
    public function verifyCode(string $secret, string $code, int $window = self::DRIFT_WINDOW, ?int $userOffset = null): bool
    {
        $code = preg_replace('/\D/', '', trim($code));
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $netOffset = $this->getNetworkTimeOffset();
        $offsetsToCheck = array_unique([
            $netOffset,                 // Network synced time
            (int)$userOffset,           // User calibrated offset
            $netOffset + (int)$userOffset,
            0                           // Local PC system time
        ]);

        foreach ($offsetsToCheck as $offset) {
            $baseTime = time() - $offset;
            $currentSlice = (int) floor($baseTime / self::PERIOD);
            for ($i = -$window; $i <= $window; $i++) {
                $calculated = $this->calculateCode($secret, $currentSlice + $i);
                if (hash_equals($calculated, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find adaptive slice offset for initial setup calibration (up to ±200 slices / ±100 minutes).
     * Discovers exact drift between user phone and computer system clock.
     */
    public function findAdaptiveSliceOffset(string $secret, string $code, int $maxSlices = 200): ?int
    {
        $code = preg_replace('/\D/', '', trim($code));
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $currentSlice = (int) floor(time() / self::PERIOD);
        for ($i = -$maxSlices; $i <= $maxSlices; $i++) {
            $calculated = $this->calculateCode($secret, $currentSlice + $i);
            if (hash_equals($calculated, $code)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Build the standard Google Authenticator provisioning URI.
     * URI format:
     * otpauth://totp/Travels%20%26%20Tours%20-%20Logistics%201:{user_email}?secret={secret}&issuer=Travels%20%26%20Tours%20-%20Logistics%201&algorithm=SHA1&digits=6&period=30
     */
    public function getProvisioningUri(string $userEmail, string $plainSecret): string
    {
        $encodedIssuer = rawurlencode(self::ISSUER);
        $encodedEmail = rawurlencode($userEmail);
        $encodedSecret = rawurlencode($plainSecret);

        return "otpauth://totp/{$encodedIssuer}:{$encodedEmail}?secret={$encodedSecret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Get or derive application encryption key.
     */
    private function getEncryptionKey(): string
    {
        $key = null;
        if (function_exists('env')) {
            $key = env('APP_KEY');
        }
        if (!$key && isset($_ENV['APP_KEY'])) {
            $key = $_ENV['APP_KEY'];
        }
        if (!$key && getenv('APP_KEY')) {
            $key = getenv('APP_KEY');
        }

        // Check .env file directly if not populated in runtime
        if (!$key) {
            $envPath = dirname(__DIR__, 2) . '/.env';
            if (is_file($envPath)) {
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(trim($line), 'APP_KEY=')) {
                        $key = trim(substr(trim($line), 8));
                        break;
                    }
                }
            }
        }

        if (empty($key)) {
            $key = 'base64:' . base64_encode(hash('sha256', 'Travels_Tours_Logistics_1_Master_Fallback_Key', true));
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        return substr(hash('sha256', (string)$key, true), 0, 32);
    }

    /**
     * Encrypt a secret at rest using AES-256-CBC compatible with Laravel's Crypt serializer.
     */
    public function encryptSecret(string $plain): string
    {
        if (class_exists('Illuminate\Support\Facades\Crypt')) {
            try {
                return \Illuminate\Support\Facades\Crypt::encryptString($plain);
            } catch (Throwable $e) {
                // fallback to native OpenSSL below
            }
        }

        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($plain, 'aes-256-cbc', $key, 0, $iv);
        if ($cipherText === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        $ivBase64 = base64_encode($iv);
        $mac = hash_hmac('sha256', $ivBase64 . $cipherText, $key);
        $payload = json_encode([
            'iv' => $ivBase64,
            'value' => $cipherText,
            'mac' => $mac,
            'tag' => ''
        ], JSON_UNESCAPED_SLASHES);

        return base64_encode((string)$payload);
    }

    /**
     * Decrypt an encrypted secret from storage.
     */
    public function decryptSecret(string $encrypted): string
    {
        if (class_exists('Illuminate\Support\Facades\Crypt')) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($encrypted);
            } catch (Throwable $e) {
                // fallback to native OpenSSL below
            }
        }

        $key = $this->getEncryptionKey();
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid encrypted payload encoding.');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload) || empty($payload['iv']) || empty($payload['value']) || empty($payload['mac'])) {
            // Check if plain fallback (legacy migration safeguard)
            if (strlen($encrypted) === 16 && ctype_alnum($encrypted)) {
                return $encrypted;
            }
            throw new RuntimeException('Malformed encryption payload.');
        }

        $iv = base64_decode((string)$payload['iv'], true);
        $cipherText = (string)$payload['value'];
        $mac = (string)$payload['mac'];

        $calculatedMac = hash_hmac('sha256', $payload['iv'] . $cipherText, $key);
        if (!hash_equals($calculatedMac, $mac)) {
            throw new RuntimeException('MAC verification failed. Secret may have been tampered with.');
        }

        $plain = openssl_decrypt($cipherText, 'aes-256-cbc', $key, 0, $iv);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    /**
     * Generate 8 single-use emergency backup recovery codes in TRVL-XXXX-XX format.
     * Example: TRVL-8921-A4
     */
    public function generateRecoveryCodes(int $count = self::RECOVERY_CODE_COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $part1 = strtoupper(bin2hex(random_bytes(2))); // 4 hex chars
            $part2 = strtoupper(bin2hex(random_bytes(1))); // 2 hex chars
            $codes[] = "TRVL-{$part1}-{$part2}";
        }
        return $codes;
    }

    /**
     * Hash an emergency recovery code using bcrypt.
     */
    public function hashRecoveryCode(string $code): string
    {
        return password_hash(trim($code), PASSWORD_BCRYPT);
    }

    /**
     * Check if OTP attempts are currently rate-limited (max 5 failed attempts per 60 seconds).
     */
    public function isRateLimited(PDO $pdo, string $identifier): bool
    {
        $threshold = time() - self::RATE_LIMIT_SECONDS;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM two_factor_rate_limits WHERE identifier = ? AND attempt_time >= ?");
        $stmt->execute([$identifier, $threshold]);
        $count = (int)$stmt->fetchColumn();

        return $count >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Record a failed OTP attempt and alert system audit if threshold reached.
     */
    public function recordFailedAttempt(PDO $pdo, string $identifier, ?int $userId = null): void
    {
        $now = time();
        $insert = $pdo->prepare("INSERT INTO two_factor_rate_limits (identifier, attempt_time) VALUES (?, ?)");
        $insert->execute([$identifier, $now]);

        $threshold = $now - self::RATE_LIMIT_SECONDS;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM two_factor_rate_limits WHERE identifier = ? AND attempt_time >= ?");
        $stmt->execute([$identifier, $threshold]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= self::MAX_FAILED_ATTEMPTS) {
            $this->logSecurityAudit(
                $pdo,
                $userId,
                'totp_failed_threshold',
                'security',
                "Brute force threshold reached: {$count} failed OTP attempts on identifier '{$identifier}' within 60s"
            );
        }
    }

    /**
     * Clear rate limits for an identifier upon successful authentication.
     */
    public function clearRateLimits(PDO $pdo, string $identifier): void
    {
        $stmt = $pdo->prepare("DELETE FROM two_factor_rate_limits WHERE identifier = ?");
        $stmt->execute([$identifier]);
    }

    /**
     * Verify and burn an emergency recovery code.
     */
    public function verifyAndBurnRecoveryCode(PDO $pdo, int $userId, string $code): bool
    {
        $code = trim($code);
        if (empty($code)) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT id, code_hash FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                // Burn the code immediately
                $now = date('Y-m-d H:i:s');
                $update = $pdo->prepare("UPDATE user_recovery_codes SET used_at = ? WHERE id = ?");
                $update->execute([$now, $row['id']]);

                $this->logSecurityAudit(
                    $pdo,
                    $userId,
                    'totp_recovery_code_used',
                    'security',
                    "User utilized single-use emergency recovery code (ID: {$row['id']})"
                );

                return true;
            }
        }

        return false;
    }

    /**
     * Confirm 2FA setup with user OTP validation and commit recovery codes.
     */
    public function confirmSetup(PDO $pdo, int $userId, string $plainSecret, string $otpCode, array $recoveryCodes): array
    {
        $code = preg_replace('/\D/', '', trim($otpCode));
        $calibratedOffsetSeconds = 0;
        $matched = false;

        // Adaptive clock drift search (determines exact drift between user phone and host clock)
        $sliceOffset = $this->findAdaptiveSliceOffset($plainSecret, $code, 200);
        if ($sliceOffset !== null) {
            $matched = true;
            $calibratedOffsetSeconds = - ($sliceOffset * self::PERIOD);
        } else {
            $matched = $this->verifyCode($plainSecret, $code);
            if ($matched) {
                $calibratedOffsetSeconds = $this->getNetworkTimeOffset();
            }
        }

        if (!$matched) {
            return [
                'success' => false,
                'error' => 'Invalid Google Authenticator code. Please check your authenticator clock and try again.'
            ];
        }

        $encryptedSecret = $this->encryptSecret($plainSecret);
        $now = date('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            // Update user record with encrypted secret and calibrated time offset
            $userUpdate = $pdo->prepare("UPDATE users SET 
                two_factor_secret = ?, 
                two_factor_enabled = 1, 
                two_factor_confirmed_at = ?,
                two_factor_recovery_codes_generated_at = ?,
                two_factor_time_offset = ?
                WHERE id = ?");
            $userUpdate->execute([$encryptedSecret, $now, $now, $calibratedOffsetSeconds, $userId]);

            // Clear old recovery codes if any
            $del = $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?");
            $del->execute([$userId]);

            // Insert new hashed recovery codes
            $ins = $pdo->prepare("INSERT INTO user_recovery_codes (user_id, code_hash, used_at, created_at) VALUES (?, ?, NULL, ?)");
            foreach ($recoveryCodes as $code) {
                $ins->execute([$userId, $this->hashRecoveryCode($code), $now]);
            }

            $this->logSecurityAudit(
                $pdo,
                $userId,
                'totp_enrolled',
                'security',
                'User successfully enrolled and activated Google Authenticator 2FA with 8 backup codes'
            );

            $pdo->commit();

            return [
                'success' => true,
                'confirmed_at' => $now,
                'recovery_codes' => $recoveryCodes
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Failed to activate 2FA: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Disable 2FA for a user.
     */
    public function disable(PDO $pdo, int $userId): bool
    {
        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare("UPDATE users SET 
                two_factor_secret = NULL, 
                two_factor_enabled = 0, 
                two_factor_confirmed_at = NULL,
                two_factor_recovery_codes_generated_at = NULL,
                two_factor_time_offset = 0
                WHERE id = ?");
            $update->execute([$userId]);

            $del = $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?");
            $del->execute([$userId]);

            $this->logSecurityAudit(
                $pdo,
                $userId,
                'totp_revoked',
                'security',
                'User disabled Google Authenticator 2FA protection'
            );

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Failed to disable 2FA: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify user password for sudo re-authentication.
     */
    public function verifySudoPassword(PDO $pdo, int $userId, string $password): bool
    {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($password, (string)$hash)) {
            return false;
        }

        return true;
    }

    /**
     * Get 2FA security status summary for a user.
     */
    public function getUserSecurityStatus(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("SELECT id, email, username, full_name, role, two_factor_enabled, two_factor_confirmed_at, two_factor_secret FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['found' => false];
        }

        $codeStmt = $pdo->prepare("SELECT 
            COUNT(*) as total, 
            SUM(CASE WHEN used_at IS NULL THEN 1 ELSE 0 END) as remaining,
            SUM(CASE WHEN used_at IS NOT NULL THEN 1 ELSE 0 END) as used
            FROM user_recovery_codes WHERE user_id = ?");
        $codeStmt->execute([$userId]);
        $codes = $codeStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'remaining' => 0, 'used' => 0];

        $isEnabled = (bool)($user['two_factor_enabled'] ?? 0);

        return [
            'found' => true,
            'user_id' => (int)$user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'is_enabled' => $isEnabled,
            'confirmed_at' => $user['two_factor_confirmed_at'] ?? null,
            'has_secret' => !empty($user['two_factor_secret']),
            'total_recovery_codes' => (int)($codes['total'] ?? 0),
            'remaining_recovery_codes' => (int)($codes['remaining'] ?? 0),
            'used_recovery_codes' => (int)($codes['used'] ?? 0),
        ];
    }

    /**
     * Internal audit logging helper dispatching to audit_logs table.
     */
    public function logSecurityAudit(PDO $pdo, ?int $userId, string $action, string $module, string $description): void
    {
        if (function_exists('logAudit')) {
            logAudit($userId, $action, $module, $description);
            return;
        }

        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $module, $description, $ip]);
        } catch (Throwable $e) {
            error_log("[TotpService Audit Error] " . $e->getMessage());
        }
    }
}
