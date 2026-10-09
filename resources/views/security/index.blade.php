<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Security & 2FA — Travels and Tours Logistics</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono-code { font-family: 'JetBrains Mono', monospace; }
        .glass-panel {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(51, 65, 85, 0.5);
        }
        .glow-accent {
            box-shadow: 0 0 25px -5px rgba(14, 165, 233, 0.25);
        }
    </style>
</head>
<body class="min-h-full flex flex-col bg-slate-950 text-slate-100 selection:bg-cyan-500 selection:text-white">

    <!-- Top Navigation Bar -->
    <header class="border-b border-slate-800/80 bg-slate-900/90 backdrop-blur sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-600 via-blue-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/20">
                    <i class="fas fa-shield-alt text-white text-lg"></i>
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="font-extrabold text-base tracking-wide bg-gradient-to-r from-white via-slate-200 to-slate-400 bg-clip-text text-transparent">Travels & Tours</span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold bg-cyan-950 text-cyan-400 border border-cyan-800/60">Logistics 1</span>
                    </div>
                    <p class="text-xs text-slate-400 font-medium">Enterprise Security & Identity Gateway</p>
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <a href="{{ url('/') }}" class="text-xs text-slate-400 hover:text-white transition flex items-center space-x-1.5 py-1.5 px-3 rounded-lg hover:bg-slate-800">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Portal</span>
                </a>
                <div class="h-6 w-px bg-slate-800"></div>
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-xs text-cyan-400">
                        {{ strtoupper(substr($user->full_name ?? $user->name ?? 'User', 0, 1)) }}
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-xs font-semibold text-slate-200">{{ $user->full_name ?? $user->name ?? 'Staff User' }}</div>
                        <div class="text-[10px] text-slate-400 uppercase tracking-wider">{{ $user->role ?? 'Staff' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-1 max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        <!-- Hero Status Banner -->
        <div class="glass-panel rounded-2xl p-6 sm:p-8 glow-accent relative overflow-hidden">
            <div class="absolute -right-16 -top-16 w-64 h-64 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="space-y-2">
                    <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-medium 
                        {{ ($securityStatus['is_enabled'] ?? false) ? 'bg-emerald-950/80 text-emerald-400 border border-emerald-800/80' : 'bg-amber-950/80 text-amber-400 border border-amber-800/80' }}">
                        <span class="w-2 h-2 rounded-full {{ ($securityStatus['is_enabled'] ?? false) ? 'bg-emerald-400 animate-pulse' : 'bg-amber-400' }}"></span>
                        <span>{{ ($securityStatus['is_enabled'] ?? false) ? '2FA ACTIVE & ENFORCED' : '2FA INACTIVE / NOT CONFIGURED' }}</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Account Security & Two-Factor Authentication</h1>
                    <p class="text-slate-400 text-sm max-w-2xl leading-relaxed">
                        Protect your Travels and Tours Logistics staff account with strict RFC 6238 Time-based One-Time Password (TOTP) enforcement via Google Authenticator.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    @if($securityStatus['is_enabled'] ?? false)
                        <button type="button" onclick="openDisableModal()" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-rose-300 bg-rose-950/40 border border-rose-800/60 hover:bg-rose-900/40 hover:border-rose-700 transition flex items-center space-x-2">
                            <i class="fas fa-lock-open"></i>
                            <span>Disable 2FA</span>
                        </button>
                        <button type="button" onclick="startSetup()" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-white bg-slate-800 border border-slate-700 hover:bg-slate-700 hover:border-slate-600 transition flex items-center space-x-2">
                            <i class="fas fa-sync-alt"></i>
                            <span>Reconfigure Authenticator</span>
                        </button>
                    @else
                        <button type="button" onclick="startSetup()" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 shadow-lg shadow-cyan-600/25 transition flex items-center space-x-2">
                            <i class="fas fa-shield-virus"></i>
                            <span>Enable Google Authenticator</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <!-- Two Column Overview Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Account Identity Card -->
            <div class="glass-panel rounded-2xl p-6 space-y-4">
                <div class="flex items-center space-x-3 text-cyan-400">
                    <i class="fas fa-id-badge text-xl"></i>
                    <h2 class="text-base font-bold text-white">Staff Identity Specs</h2>
                </div>
                <div class="space-y-3 pt-2 text-xs">
                    <div class="flex justify-between items-center py-2 border-b border-slate-800">
                        <span class="text-slate-400">Work Email</span>
                        <span class="font-mono-code font-semibold text-slate-200">{{ $user->email ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-slate-800">
                        <span class="text-slate-400">Assigned Role</span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-800 text-slate-300 border border-slate-700 uppercase">
                            {{ $user->role ?? 'Staff' }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-slate-800">
                        <span class="text-slate-400">Protection Status</span>
                        <span class="font-semibold {{ ($securityStatus['is_enabled'] ?? false) ? 'text-emerald-400' : 'text-amber-400' }}">
                            {{ ($securityStatus['is_enabled'] ?? false) ? 'Protected' : 'Vulnerable' }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center py-2">
                        <span class="text-slate-400">Enrolled Since</span>
                        <span class="text-slate-300">{{ $securityStatus['confirmed_at'] ? date('M d, Y H:i', strtotime($securityStatus['confirmed_at'])) : 'Not Enrolled' }}</span>
                    </div>
                </div>
            </div>

            <!-- Emergency Recovery Codes Status Card -->
            <div class="glass-panel rounded-2xl p-6 space-y-4">
                <div class="flex items-center space-x-3 text-indigo-400">
                    <i class="fas fa-key text-xl"></i>
                    <h2 class="text-base font-bold text-white">Emergency Access Vault</h2>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed">
                    Single-use emergency codes format: <code class="font-mono-code text-cyan-300 bg-slate-900 px-1 py-0.5 rounded">TRVL-XXXX-XX</code>. Keep these in physical or password manager storage.
                </p>
                <div class="bg-slate-900/80 rounded-xl p-4 border border-slate-800/80 flex items-center justify-between">
                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Available Codes</div>
                        <div class="text-2xl font-extrabold text-white">
                            {{ $securityStatus['remaining_recovery_codes'] ?? 0 }}
                            <span class="text-xs font-normal text-slate-500">/ 8 codes</span>
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-lg bg-indigo-950/60 border border-indigo-800/40 flex items-center justify-center text-indigo-400">
                        <i class="fas fa-vault"></i>
                    </div>
                </div>
                @if(($securityStatus['is_enabled'] ?? false) && ($securityStatus['remaining_recovery_codes'] ?? 0) < 3)
                    <div class="text-xs p-3 rounded-xl bg-amber-950/40 border border-amber-800/40 text-amber-300 flex items-start space-x-2">
                        <i class="fas fa-exclamation-triangle mt-0.5"></i>
                        <span>Low recovery codes remaining. Reconfigure 2FA soon to refresh your 8 emergency codes.</span>
                    </div>
                @endif
            </div>

            <!-- RFC 6238 Compliance Details -->
            <div class="glass-panel rounded-2xl p-6 space-y-4">
                <div class="flex items-center space-x-3 text-emerald-400">
                    <i class="fas fa-fingerprint text-xl"></i>
                    <h2 class="text-base font-bold text-white">Cryptographic Standards</h2>
                </div>
                <ul class="text-xs space-y-2.5 text-slate-400 pt-1">
                    <li class="flex items-center space-x-2">
                        <i class="fas fa-check text-emerald-400 text-[10px]"></i>
                        <span><strong class="text-slate-200">Protocol:</strong> RFC 6238 TOTP (HMAC-SHA1)</span>
                    </li>
                    <li class="flex items-center space-x-2">
                        <i class="fas fa-check text-emerald-400 text-[10px]"></i>
                        <span><strong class="text-slate-200">Time-step:</strong> 30 seconds with ±1 window drift</span>
                    </li>
                    <li class="flex items-center space-x-2">
                        <i class="fas fa-check text-emerald-400 text-[10px]"></i>
                        <span><strong class="text-slate-200">Storage:</strong> AES-256-CBC Encrypted At Rest</span>
                    </li>
                    <li class="flex items-center space-x-2">
                        <i class="fas fa-check text-emerald-400 text-[10px]"></i>
                        <span><strong class="text-slate-200">Anti-Brute Force:</strong> 5 failed attempts/min throttle</span>
                    </li>
                    <li class="flex items-center space-x-2">
                        <i class="fas fa-check text-emerald-400 text-[10px]"></i>
                        <span><strong class="text-slate-200">Issuer Branding:</strong> Travels & Tours - Logistics 1</span>
                    </li>
                </ul>
            </div>
        </div>
    </main>

    <!-- Sudo Re-Authentication Modal -->
    <div id="sudoModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="glass-panel w-full max-w-md rounded-2xl p-6 space-y-5 border border-slate-700/80 shadow-2xl relative">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-xl bg-amber-950/60 border border-amber-800/40 flex items-center justify-center text-amber-400">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">Sudo Re-Authentication</h3>
                        <p class="text-xs text-slate-400">Security checkpoint required</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('sudoModal')" class="text-slate-400 hover:text-white transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p class="text-xs text-slate-300 leading-relaxed">
                Confirm your account password to authorize provisioning or reconfiguring Google Authenticator.
            </p>

            <form id="sudoForm" onsubmit="handleSudoSubmit(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Account Password</label>
                    <input type="password" id="sudoPassword" required placeholder="Enter current password"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500">
                </div>

                <div id="sudoError" class="text-xs text-rose-400 bg-rose-950/40 border border-rose-800/40 p-2.5 rounded-lg hidden"></div>

                <div class="flex items-center justify-end space-x-3 pt-2">
                    <button type="button" onclick="closeModal('sudoModal')" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white bg-slate-800/60 hover:bg-slate-800 transition">
                        Cancel
                    </button>
                    <button type="submit" id="sudoSubmitBtn" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-cyan-600 hover:bg-cyan-500 transition shadow-lg shadow-cyan-600/20 flex items-center space-x-2">
                        <i class="fas fa-unlock"></i>
                        <span>Verify & Proceed</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2FA Provisioning Wizard Modal -->
    <div id="setupModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
        <div class="glass-panel w-full max-w-2xl rounded-2xl p-6 sm:p-8 space-y-6 border border-slate-700 shadow-2xl relative my-8">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-cyan-950/60 border border-cyan-800/50 flex items-center justify-center text-cyan-400">
                        <i class="fas fa-qrcode text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-base sm:text-lg font-bold text-white">Link Google Authenticator</h3>
                        <p class="text-xs text-slate-400">Issuer: Travels & Tours - Logistics 1</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('setupModal')" class="text-slate-400 hover:text-white transition">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <!-- Step 1: Scan QR Code -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-center">
                <div class="flex flex-col items-center justify-center p-4 bg-white rounded-2xl shadow-inner mx-auto w-56 h-56">
                    <div id="qrcodeCanvas"></div>
                </div>
                <div class="space-y-3 text-xs">
                    <div class="font-bold text-slate-200 uppercase tracking-wider text-[11px] text-cyan-400">Step 1: Scan With App</div>
                    <p class="text-slate-300 leading-relaxed">
                        Open <strong>Google Authenticator</strong> (or Microsoft Authenticator / Authy) on your phone, tap <strong>"+"</strong> and select <strong>"Scan a QR code"</strong>.
                    </p>
                    <div class="pt-2">
                        <div class="text-[11px] text-slate-400 font-semibold mb-1">Or enter key manually:</div>
                        <div class="flex items-center space-x-2">
                            <input type="text" id="manualSecretInput" readonly class="font-mono-code bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-cyan-300 w-full">
                            <button type="button" onclick="copySecret()" title="Copy Key" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs transition">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Emergency Recovery Codes Preview -->
            <div class="bg-slate-900/90 rounded-2xl p-4 sm:p-5 border border-slate-800 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2 text-xs font-bold text-white">
                        <i class="fas fa-key text-indigo-400"></i>
                        <span>Step 2: Save Emergency Recovery Codes (8 Single-Use)</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="downloadCodesTxt()" class="text-[11px] px-2.5 py-1 rounded-lg bg-indigo-950/60 border border-indigo-800/40 text-indigo-300 hover:bg-indigo-900 transition flex items-center space-x-1">
                            <i class="fas fa-download"></i>
                            <span>Download .txt</span>
                        </button>
                        <button type="button" onclick="printCodes()" class="text-[11px] px-2.5 py-1 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 transition flex items-center space-x-1">
                            <i class="fas fa-print"></i>
                            <span>Print</span>
                        </button>
                    </div>
                </div>
                <div id="recoveryCodesGrid" class="grid grid-cols-2 sm:grid-cols-4 gap-2 font-mono-code text-[11px] text-slate-200">
                    <!-- Populated via JS -->
                </div>
                <p class="text-[11px] text-slate-400">
                    <i class="fas fa-info-circle text-cyan-400 mr-1"></i> These will not be visible again once activated. Store them securely.
                </p>
            </div>

            <!-- Step 3: Enter 6-digit verification code -->
            <form id="confirmSetupForm" onsubmit="handleConfirmSubmit(event)" class="space-y-4 pt-2">
                <div>
                    <label class="block text-xs font-bold text-slate-200 mb-1.5 uppercase tracking-wider text-cyan-400">
                        Step 3: Verify 6-Digit Authenticator Code
                    </label>
                    <div class="flex items-center space-x-3">
                        <input type="text" id="verifyTotpCode" maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
                            class="font-mono-code text-center text-xl tracking-[0.3em] font-bold w-48 px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20">
                        <span class="text-xs text-slate-400">Enter the rotating code from Google Authenticator</span>
                    </div>
                </div>

                <div id="confirmError" class="text-xs text-rose-400 bg-rose-950/40 border border-rose-800/40 p-2.5 rounded-lg hidden"></div>

                <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
                    <button type="button" onclick="closeModal('setupModal')" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white bg-slate-800/60 hover:bg-slate-800 transition">
                        Cancel
                    </button>
                    <button type="submit" id="confirmSubmitBtn" class="px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 transition shadow-lg shadow-emerald-600/25 flex items-center space-x-2">
                        <i class="fas fa-check-circle"></i>
                        <span>Verify & Activate 2FA</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Disable 2FA Modal -->
    <div id="disableModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="glass-panel w-full max-w-md rounded-2xl p-6 space-y-5 border border-rose-900/50 shadow-2xl relative">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-xl bg-rose-950/60 border border-rose-800/40 flex items-center justify-center text-rose-400">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">Disable 2FA Protection</h3>
                        <p class="text-xs text-slate-400">Requires Sudo Authorization</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('disableModal')" class="text-slate-400 hover:text-white transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p class="text-xs text-slate-300 leading-relaxed">
                Disabling 2FA will remove Google Authenticator requirements and burn all current recovery codes. Your account will rely only on your password.
            </p>

            <form id="disableForm" onsubmit="handleDisableSubmit(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Confirm Account Password</label>
                    <input type="password" id="disablePassword" required placeholder="Enter password to confirm"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500">
                </div>

                <div id="disableError" class="text-xs text-rose-400 bg-rose-950/40 border border-rose-800/40 p-2.5 rounded-lg hidden"></div>

                <div class="flex items-center justify-end space-x-3 pt-2">
                    <button type="button" onclick="closeModal('disableModal')" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white bg-slate-800/60 hover:bg-slate-800 transition">
                        Cancel
                    </button>
                    <button type="submit" id="disableSubmitBtn" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-500 transition shadow-lg shadow-rose-600/25 flex items-center space-x-2">
                        <i class="fas fa-trash-alt"></i>
                        <span>Disable 2FA</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Client-Side State & Logic -->
    <script>
        let currentSecret = '';
        let currentRecoveryCodes = [];
        let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function startSetup() {
            document.getElementById('sudoPassword').value = '';
            document.getElementById('sudoError').classList.add('hidden');
            openModal('sudoModal');
        }

        function openDisableModal() {
            document.getElementById('disablePassword').value = '';
            document.getElementById('disableError').classList.add('hidden');
            openModal('disableModal');
        }

        function openModal(id) {
            const m = document.getElementById(id);
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleSudoSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('sudoSubmitBtn');
            const err = document.getElementById('sudoError');
            const password = document.getElementById('sudoPassword').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Verifying...</span>';
            err.classList.add('hidden');

            try {
                const res = await fetch("{{ route('system.account.security.provision') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ sudo_password: password })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Password confirmation failed.');
                }

                currentSecret = data.secret;
                currentRecoveryCodes = data.recovery_codes || [];

                closeModal('sudoModal');
                renderSetupWizard(data.provisioning_uri, data.secret, data.recovery_codes);
            } catch (error) {
                err.textContent = error.message;
                err.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-unlock"></i><span>Verify & Proceed</span>';
            }
        }

        function renderSetupWizard(uri, secret, codes) {
            // Render QR Code
            const qrContainer = document.getElementById('qrcodeCanvas');
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: uri,
                width: 190,
                height: 190,
                colorDark: "#0A1628",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });

            document.getElementById('manualSecretInput').value = secret;

            // Render Recovery Codes Grid
            const grid = document.getElementById('recoveryCodesGrid');
            grid.innerHTML = '';
            codes.forEach((code, index) => {
                const el = document.createElement('div');
                el.className = 'bg-slate-950/80 border border-slate-800 p-2 rounded-lg text-center select-all font-semibold';
                el.textContent = code;
                grid.appendChild(el);
            });

            document.getElementById('verifyTotpCode').value = '';
            document.getElementById('confirmError').classList.add('hidden');
            openModal('setupModal');
        }

        document.addEventListener('DOMContentLoaded', () => {
            const totpInput = document.getElementById('verifyTotpCode');
            if (totpInput) {
                totpInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '').slice(0, 6);
                });
            }
        });

        async function handleConfirmSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('confirmSubmitBtn');
            const err = document.getElementById('confirmError');
            const codeRaw = document.getElementById('verifyTotpCode').value;
            const code = codeRaw.replace(/\D/g, '').trim();

            if (code.length !== 6) {
                err.textContent = 'Please enter the 6-digit verification code from your Google Authenticator app.';
                err.classList.remove('hidden');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Activating...</span>';
            err.classList.add('hidden');

            try {
                const res = await fetch("{{ route('system.account.security.confirm') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        secret: currentSecret,
                        code: code,
                        recovery_codes: currentRecoveryCodes
                    })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Invalid verification code.');
                }

                alert('Success! Two-Factor Authentication is now active and protecting your account.');
                window.location.reload();
            } catch (error) {
                err.textContent = error.message;
                err.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i><span>Verify & Activate 2FA</span>';
            }
        }

        async function handleDisableSubmit(e) {
            e.preventDefault();
            const btn = document.getElementById('disableSubmitBtn');
            const err = document.getElementById('disableError');
            const password = document.getElementById('disablePassword').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Processing...</span>';
            err.classList.add('hidden');

            try {
                const res = await fetch("{{ route('system.account.security.disable') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ sudo_password: password })
                });
                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Failed to disable 2FA.');
                }

                alert('Two-Factor Authentication has been disabled.');
                window.location.reload();
            } catch (error) {
                err.textContent = error.message;
                err.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i><span>Disable 2FA</span>';
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
                       "Account: {{ $user->email }}\n" +
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
            a.download = `travels-2fa-recovery-codes-${Date.now()}.txt`;
            a.click();
            URL.revokeObjectURL(url);
        }

        function printCodes() {
            const w = window.open('', '_blank');
            let html = `<html><head><title>Print Recovery Codes</title><style>body{font-family:monospace;padding:30px;line-height:1.6;}h2{margin-bottom:5px;}</style></head><body>`;
            html += `<h2>Travels & Tours - Logistics 1</h2>`;
            html += `<p>Emergency Backup Recovery Codes — Account: {{ $user->email }}</p><hr><ol>`;
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
