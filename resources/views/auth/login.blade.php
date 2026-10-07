<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In – Toursphere Travel &amp; Tours</title>
    <meta name="description" content="Sign in to Toursphere Travel &amp; Tours Supply Chain &amp; Logistics portal.">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --brand-dark:#0A1628; --brand-blue:#1A6FD4; --brand-cyan:#00C2FF; --brand-light:#56D9FF;
            --card-bg:rgba(255,255,255,0.06); --card-border:rgba(255,255,255,0.14);
            --input-bg:rgba(255,255,255,0.09); --input-border:rgba(255,255,255,0.2);
            --text-main:#F0F6FF; --text-muted:rgba(200,220,255,0.65);
            --shadow:0 30px 80px rgba(0,0,0,0.55);
        }
        body {
            font-family:'Poppins',sans-serif; min-height:100vh; display:flex;
            align-items:center; justify-content:center; overflow-x:hidden;
            background:var(--brand-dark); position:relative;
        }
        .bg-canvas {
            position:fixed; inset:0; z-index:0;
            background:linear-gradient(135deg,#040D1C 0%,#0B1E42 35%,#0F3578 65%,#0A1A3A 100%);
        }
        .orb { position:absolute; border-radius:50%; filter:blur(80px); opacity:0.35; animation:float 12s ease-in-out infinite; }
        .orb-1 { width:520px;height:520px;background:radial-gradient(circle,#1A6FD4,transparent);top:-120px;left:-160px;animation-delay:0s; }
        .orb-2 { width:400px;height:400px;background:radial-gradient(circle,#00C2FF,transparent);bottom:-100px;right:-120px;animation-delay:-4s; }
        .orb-3 { width:280px;height:280px;background:radial-gradient(circle,#0D4A9E,transparent);top:40%;left:55%;animation-delay:-8s; }
        @keyframes float { 0%,100%{transform:translateY(0) scale(1);} 50%{transform:translateY(-30px) scale(1.05);} }
        .bg-grid {
            position:absolute; inset:0;
            background-image:linear-gradient(rgba(0,194,255,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,194,255,0.04) 1px,transparent 1px);
            background-size:50px 50px;
        }
        .plane-trail {
            position:absolute; top:18%; left:-140px; display:flex; align-items:center;
            animation:flyAcross 18s linear infinite; opacity:0.18; pointer-events:none;
        }
        .plane-trail i { font-size:28px; color:#56D9FF; }
        .plane-trail .trail { width:100px;height:2px;background:linear-gradient(to left,rgba(86,217,255,0.6),transparent);margin-right:4px; }
        @keyframes flyAcross { 0%{left:-140px;opacity:0;} 5%{opacity:0.18;} 90%{opacity:0.18;} 100%{left:110vw;opacity:0;} }
        .page-wrapper {
            position:relative; z-index:10; width:100%; max-width:1100px;
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:30px 20px;
        }
        .login-card {
            width:100%; max-width:460px; background:var(--card-bg); border:1px solid var(--card-border);
            border-radius:28px; padding:52px 44px 44px; box-shadow:var(--shadow);
            backdrop-filter:blur(24px); -webkit-backdrop-filter:blur(24px);
            animation:cardIn .5s cubic-bezier(.22,1,.36,1);
        }
        @keyframes cardIn { from{opacity:0;transform:translateY(28px) scale(.97);} to{opacity:1;transform:translateY(0) scale(1);} }
        .brand-header { text-align:center; margin-bottom:36px; }
        .brand-logo {
            width:110px;height:110px;object-fit:contain;margin:0 auto 16px;display:block;border-radius:50%;
            box-shadow:0 0 0 4px rgba(0,194,255,0.2),0 8px 32px rgba(0,100,200,0.5);
            transition:transform .4s ease,box-shadow .4s ease;
        }
        .brand-logo:hover { transform:rotate(5deg) scale(1.05); box-shadow:0 0 0 6px rgba(0,194,255,0.35),0 12px 40px rgba(0,100,200,0.7); }
        .brand-icon-fallback {
            width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,#1A6FD4,#00C2FF);
            display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:48px;
        }
        .brand-name {
            font-size:26px;font-weight:800;letter-spacing:-0.5px;
            background:linear-gradient(135deg,#FFFFFF 0%,#56D9FF 100%);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1.1;
        }
        .brand-tagline { font-size:12px;font-weight:500;color:var(--text-muted);letter-spacing:2.5px;text-transform:uppercase;margin-top:4px; }
        .brand-divider {
            display:flex;align-items:center;gap:10px;margin-top:14px;
            color:var(--text-muted);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;
        }
        .brand-divider::before,.brand-divider::after { content:'';flex:1;height:1px;background:var(--card-border); }
        .alert {
            display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:14px;
            font-size:13px;line-height:1.5;margin-bottom:22px;animation:alertIn .3s ease;
        }
        @keyframes alertIn { from{opacity:0;transform:translateY(-6px);} to{opacity:1;transform:none;} }
        .alert i { font-size:16px;margin-top:1px;flex-shrink:0; }
        .alert-error   { background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.35);color:#FCA5A5; }
        .alert-warning { background:rgba(251,191,36,0.12);border:1px solid rgba(251,191,36,0.3);color:#FDE68A; }
        .form-heading { font-size:18px;font-weight:700;color:var(--text-main);margin-bottom:22px; }
        .form-group { margin-bottom:18px; }
        .form-label { display:block;font-size:12px;font-weight:600;color:var(--text-muted);letter-spacing:0.8px;text-transform:uppercase;margin-bottom:8px; }
        .input-wrapper { position:relative; }
        .input-icon { position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--brand-cyan);font-size:15px;pointer-events:none;transition:color .3s; }
        .form-input {
            width:100%;padding:13px 16px 13px 44px;background:var(--input-bg);border:1.5px solid var(--input-border);
            border-radius:14px;font-family:'Poppins',sans-serif;font-size:14px;color:var(--text-main);transition:all .3s ease;outline:none;
        }
        .form-input::placeholder { color:rgba(160,200,255,0.4); }
        .form-input:focus { border-color:var(--brand-cyan);background:rgba(0,194,255,0.08);box-shadow:0 0 0 4px rgba(0,194,255,0.12); }
        .input-wrapper:focus-within .input-icon { color:var(--brand-light); }
        .pw-toggle {
            position:absolute;right:16px;top:50%;transform:translateY(-50%);background:none;border:none;
            color:var(--text-muted);cursor:pointer;font-size:15px;padding:4px;transition:color .3s;
        }
        .pw-toggle:hover { color:var(--brand-cyan); }
        .form-options { display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;font-size:13px; }
        .remember-label { display:flex;align-items:center;gap:8px;color:var(--text-muted);cursor:pointer;user-select:none; }
        .remember-label input[type="checkbox"] { width:16px;height:16px;accent-color:var(--brand-cyan);cursor:pointer; }
        .forgot-link { color:var(--brand-cyan);text-decoration:none;font-weight:600;font-size:13px;transition:all .3s; }
        .forgot-link:hover { color:var(--brand-light);text-decoration:underline; }
        .btn-login {
            width:100%;padding:15px;background:linear-gradient(135deg,var(--brand-blue) 0%,var(--brand-cyan) 100%);
            color:#fff;border:none;border-radius:14px;font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;
            cursor:pointer;transition:all .3s ease;display:flex;align-items:center;justify-content:center;gap:10px;
            box-shadow:0 8px 30px rgba(0,150,255,0.35);position:relative;overflow:hidden;
        }
        .btn-login:hover { transform:translateY(-3px);box-shadow:0 14px 40px rgba(0,150,255,0.5); }
        .btn-login:active { transform:translateY(-1px); }
        .btn-login .spinner {
            display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);
            border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;
        }
        @keyframes spin { to{transform:rotate(360deg);} }
        .btn-login.loading .spinner { display:block; }
        .btn-login.loading .btn-text { display:none; }
        .login-footer { margin-top:28px;text-align:center; }
        .credentials-hint {
            background:rgba(0,194,255,0.08);border:1px solid rgba(0,194,255,0.18);
            border-radius:12px;padding:12px 16px;font-size:12px;color:var(--text-muted);
        }
        @media (max-width:500px) { .login-card{padding:36px 24px 30px;border-radius:20px;} }
        @media (max-height:760px) { .page-wrapper{align-items:flex-start;} }
    </style>
</head>
<body>

<div class="bg-canvas">
    <div class="bg-grid"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="plane-trail">
        <span class="trail"></span>
        <i class="fas fa-plane"></i>
    </div>
</div>

<div class="page-wrapper">
    <div class="login-card">

        <div class="brand-header">
            <img
                src="/assets/image/toursphere_logo.png"
                alt="Toursphere Logo"
                class="brand-logo"
                id="brandLogo"
                onerror="this.style.display='none';document.getElementById('fallbackIcon').style.display='flex';"
            >
            <div id="fallbackIcon" style="display:none;" class="brand-icon-fallback">✈️</div>
            <div class="brand-name">Toursphere</div>
            <div class="brand-tagline">Travel &amp; Tours</div>
            <div class="brand-divider">Supply Chain &amp; Logistics Portal</div>
        </div>

        @if(request('timeout') === '1')
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i>
            <span>Your session expired due to inactivity. Please sign in again.</span>
        </div>
        @endif

        @if($errors->any())
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>{{ $errors->first() }}</span>
        </div>
        @endif

        <p class="form-heading">Welcome back 👋</p>

        <form method="POST" action="{{ route('login.submit') }}" id="loginForm" novalidate>
            @csrf
            <div class="form-group">
                <label class="form-label" for="email">Email or Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="text" id="email" name="email" class="form-input"
                        placeholder="you@toursphere.com" value="{{ old('email') }}"
                        autocomplete="username" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="form-input"
                        placeholder="Enter your password" autocomplete="current-password" required>
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
                        <i class="fas fa-eye" id="pwToggleIcon"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label class="remember-label">
                    <input type="checkbox" name="remember" id="rememberMe"> Remember me
                </label>
                <a href="/forgot-password" class="forgot-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">
                <span class="spinner"></span>
                <span class="btn-text"><i class="fas fa-sign-in-alt"></i>&nbsp; Sign In</span>
            </button>
        </form>

        <div class="login-footer">
            <div class="credentials-hint">
                <i class="fas fa-shield-alt" style="color:rgba(0,194,255,.6);"></i>
                &nbsp;Contact your administrator for access credentials.
            </div>
        </div>

    </div>
</div>

<script>
    var pwInput  = document.getElementById('password');
    var pwToggle = document.getElementById('pwToggle');
    var pwIcon   = document.getElementById('pwToggleIcon');
    pwToggle.addEventListener('click', function () {
        var isText = pwInput.type === 'text';
        pwInput.type = isText ? 'password' : 'text';
        pwIcon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
    });
    document.getElementById('loginForm').addEventListener('submit', function () {
        var btn = document.getElementById('loginBtn');
        btn.classList.add('loading');
        btn.disabled = true;
    });
</script>
</body>
</html>
