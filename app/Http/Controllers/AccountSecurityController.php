<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmTotpSetupRequest;
use App\Http\Requests\DisableTotpRequest;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountSecurityController extends Controller
{
    public function __construct(
        protected TotpService $totpService
    ) {}

    /**
     * Show the Account Security & 2FA management panel.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $pdo = DB::connection()->getPdo();
        $securityStatus = $this->totpService->getUserSecurityStatus($pdo, (int)$user->id);

        return view('security.index', [
            'user' => $user,
            'securityStatus' => $securityStatus,
        ]);
    }

    /**
     * Sudo re-authenticate and initialize 2FA provisioning.
     */
    public function provision(Request $request): JsonResponse
    {
        $request->validate([
            'sudo_password' => ['required', 'string'],
        ]);

        $user = $request->user();
        $pdo = DB::connection()->getPdo();

        if (!$this->totpService->verifySudoPassword($pdo, (int)$user->id, $request->input('sudo_password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Password confirmation failed. Please enter your correct current account password.'
            ], 403);
        }

        $secret = $this->totpService->generateSecret(16);
        $provisioningUri = $this->totpService->getProvisioningUri((string)$user->email, $secret);
        $recoveryCodes = $this->totpService->generateRecoveryCodes();

        // Stash pending provisioning in session
        $request->session()->put('totp_pending_setup', [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'secret' => $secret,
            'provisioning_uri' => $provisioningUri,
            'recovery_codes' => $recoveryCodes,
            'issuer' => TotpService::ISSUER,
            'label' => $user->email,
        ]);
    }

    /**
     * Confirm 6-digit TOTP code and enable 2FA with emergency codes.
     */
    public function confirm(ConfirmTotpSetupRequest $request): JsonResponse
    {
        $user = $request->user();
        $pdo = DB::connection()->getPdo();

        $identifier = 'user_' . $user->id;
        if ($this->totpService->isRateLimited($pdo, $identifier)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed verification attempts. Please wait 60 seconds before trying again.'
            ], 429);
        }

        $secret = $request->input('secret');
        $code = $request->input('code');
        $recoveryCodes = $request->input('recovery_codes');

        $result = $this->totpService->confirmSetup($pdo, (int)$user->id, $secret, $code, $recoveryCodes);

        if (!$result['success']) {
            $this->totpService->recordFailedAttempt($pdo, $identifier, (int)$user->id);
            return response()->json([
                'success' => false,
                'message' => $result['error']
            ], 422);
        }

        $this->totpService->clearRateLimits($pdo, $identifier);
        $request->session()->forget('totp_pending_setup');

        return response()->json([
            'success' => true,
            'message' => 'Google Authenticator 2FA has been successfully activated for your account!',
            'confirmed_at' => $result['confirmed_at']
        ]);
    }

    /**
     * Disable 2FA with Sudo Re-Authentication.
     */
    public function disable(DisableTotpRequest $request): JsonResponse
    {
        $user = $request->user();
        $pdo = DB::connection()->getPdo();

        if (!$this->totpService->verifySudoPassword($pdo, (int)$user->id, $request->input('sudo_password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Password confirmation failed. Please enter your correct current account password.'
            ], 403);
        }

        $this->totpService->disable($pdo, (int)$user->id);

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication has been disabled for your account.'
        ]);
    }

    /**
     * Download backup recovery codes as a plain text file.
     */
    public function downloadRecoveryCodes(Request $request): Response
    {
        $user = $request->user();
        $pdo = DB::connection()->getPdo();

        $codes = $request->session()->get('totp_pending_setup.recovery_codes', []);
        if (empty($codes)) {
            // If already committed, users can view active codes count or freshly generated bundle
            $codes = $request->input('codes', []);
        }

        $content = "========================================================\n";
        $content .= "   TRAVELS & TOURS - LOGISTICS 1\n";
        $content .= "   EMERGENCY BACKUP RECOVERY CODES\n";
        $content .= "========================================================\n\n";
        $content .= "Account: " . ($user->email ?? 'Staff Account') . "\n";
        $content .= "Generated: " . date('Y-m-d H:i:s T') . "\n\n";
        $content .= "KEEP THESE CODES SECURE. Each code can be used ONCE to access\n";
        $content .= "your account in case you lose your Google Authenticator device.\n\n";
        $content .= "--------------------------------------------------------\n";
        foreach ($codes as $idx => $code) {
            $num = $idx + 1;
            $content .= "[{$num}] {$code}\n";
        }
        $content .= "--------------------------------------------------------\n";

        $filename = 'travels-logistics-2fa-recovery-codes-' . date('Ymd-His') . '.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
