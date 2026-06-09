<?php
require 'db.php'; // Tinatawag natin ang koneksyon sa database

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        // TRENDING SECURITY: Hina-hash natin ang password gamit ang BCRYPT. Hindi ito mada-data breach!
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        try {
            // Gumagamit tayo ng Prepared Statements para iwas SQL Injection
            $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute([$username, $hashed_password]);
            $message = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Error code para sa Duplicate Entry
                $message = 'taken';
            } else {
                $message = 'error';
            }
        }
    } else {
        $message = 'empty';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentimo — Register</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 14px; }

    :root {
        --bg:        #080E1C;
        --bg2:       #0D1426;
        --surface:   rgba(255,255,255,.04);
        --surface-h: rgba(255,255,255,.07);
        --border:    rgba(255,255,255,.08);
        --border-h:  rgba(59,130,246,.4);
        --blue:      #3B82F6;
        --blue-light:#60A5FA;
        --blue-glow: rgba(59,130,246,.25);
        --blue-dim:  rgba(59,130,246,.12);
        --indigo:    #818CF8;
        --sky:       #38BDF8;
        --text-1:    #F1F5F9;
        --text-2:    #94A3B8;
        --text-3:    #475569;
        --expense-clr: #F87171;
        --expense-bg:  rgba(248,113,113,.12);
        --success-clr: #4ADE80;
        --success-bg:  rgba(74,222,128,.12);
        --font:      'Inter', system-ui, sans-serif;
        --r-sm: 8px;
        --r-md: 14px;
        --r-lg: 20px;
    }

    body {
        font-family: var(--font);
        background: var(--bg);
        color: var(--text-1);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }

    /* ── Background glows ── */
    body::before {
        content: '';
        position: fixed;
        top: -180px; left: 30px;
        width: 800px; height: 800px;
        background: radial-gradient(circle, rgba(59,130,246,.22) 0%, rgba(99,102,241,.10) 40%, transparent 70%);
        pointer-events: none;
    }
    body::after {
        content: '';
        position: fixed;
        bottom: -80px; right: -60px;
        width: 680px; height: 680px;
        background: radial-gradient(circle, rgba(56,189,248,.15) 0%, rgba(129,140,248,.10) 40%, transparent 70%);
        pointer-events: none;
    }
    .bg-mid {
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 900px; height: 500px;
        background: radial-gradient(ellipse, rgba(99,102,241,.07) 0%, transparent 65%);
        pointer-events: none;
    }

    /* ── Card ── */
    .login-wrap {
        position: relative;
        z-index: 10;
        width: 100%;
        max-width: 400px;
        padding: 16px;
    }

    /* Brand row */
    .brand {
        display: flex;
        align-items: center;
        gap: 10px;
        justify-content: center;
        margin-bottom: 32px;
    }
    .brand-icon {
        width: 42px; height: 42px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border-radius: var(--r-sm);
        display: grid; place-items: center;
        box-shadow: 0 0 24px rgba(59,130,246,.35);
    }
    .brand-icon svg { width: 22px; height: 22px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .brand-name {
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -.04em;
        background: linear-gradient(90deg, #fff 0%, var(--blue-light) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .card {
        background: rgba(13,20,38,.75);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        backdrop-filter: blur(24px);
        padding: 32px 28px 28px;
        box-shadow: 0 24px 64px rgba(0,0,0,.4), 0 0 0 1px rgba(59,130,246,.06);
    }

    .card-title {
        font-size: 1.25rem;
        font-weight: 800;
        letter-spacing: -.03em;
        margin-bottom: 4px;
    }
    .card-sub {
        font-size: .8rem;
        color: var(--text-2);
        margin-bottom: 24px;
    }

    /* ── Alerts ── */
    .alert {
        display: flex; align-items: center; gap: 9px;
        padding: 11px 14px;
        border-radius: var(--r-sm);
        font-size: .78rem; font-weight: 500;
        margin-bottom: 18px;
        animation: fadeDown .2s ease;
    }
    .alert.danger {
        background: var(--expense-bg);
        color: var(--expense-clr);
        border: 1px solid rgba(248,113,113,.2);
    }
    .alert.success {
        background: var(--success-bg);
        color: var(--success-clr);
        border: 1px solid rgba(74,222,128,.2);
    }
    .alert svg { width: 14px; height: 14px; flex-shrink: 0; }
    @keyframes fadeDown { from { opacity:0; transform:translateY(-5px); } to { opacity:1; transform:translateY(0); } }

    /* ── Fields ── */
    .field { margin-bottom: 16px; }
    .field label {
        display: block;
        font-size: .72rem; font-weight: 600;
        color: var(--text-2);
        margin-bottom: 7px;
        letter-spacing: .03em;
        text-transform: uppercase;
    }
    .input-wrap { position: relative; }
    .input-wrap .input-icon {
        position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
        width: 15px; height: 15px;
        color: var(--text-3);
        pointer-events: none;
    }
    .field input {
        width: 100%;
        padding: 10px 13px 10px 38px;
        background: rgba(255,255,255,.05);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        color: var(--text-1);
        font-family: var(--font);
        font-size: .85rem;
        outline: none;
        transition: border-color .15s, box-shadow .15s;
    }
    .field input::placeholder { color: var(--text-3); }
    .field input:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 3px var(--blue-glow);
    }

    /* Password toggle */
    .pw-toggle {
        position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer;
        color: var(--text-3); padding: 2px;
        display: grid; place-items: center;
        transition: color .15s;
    }
    .pw-toggle:hover { color: var(--text-2); }
    .pw-toggle svg { width: 15px; height: 15px; }

    /* ── Submit ── */
    .submit-btn {
        width: 100%;
        padding: 11px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border: none; border-radius: var(--r-sm);
        color: #fff;
        font-family: var(--font); font-size: .88rem; font-weight: 700;
        cursor: pointer;
        letter-spacing: .01em;
        margin-top: 8px;
        transition: opacity .15s, transform .1s, box-shadow .15s;
        box-shadow: 0 4px 18px rgba(59,130,246,.3);
    }
    .submit-btn:hover  { opacity: .9; box-shadow: 0 6px 24px rgba(59,130,246,.45); }
    .submit-btn:active { transform: scale(.98); }

    /* ── Footer link ── */
    .card-footer {
        text-align: center;
        margin-top: 20px;
        font-size: .78rem;
        color: var(--text-2);
    }
    .card-footer a {
        color: var(--blue-light);
        text-decoration: none;
        font-weight: 600;
        transition: color .15s;
    }
    .card-footer a:hover { color: #fff; }

    /* ── Tagline under card ── */
    .tagline {
        text-align: center;
        margin-top: 20px;
        font-size: .72rem;
        color: var(--text-3);
        letter-spacing: .02em;
    }
    </style>
</head>
<body>

<div class="bg-mid"></div>

<div class="login-wrap">

    <div class="brand">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9"/>
                <path d="M14.5 9a3 3 0 0 0-5 2c0 1.5 1 2.5 2.5 3s2.5 1.5 2.5 3a3 3 0 0 1-5 2"/>
                <line x1="12" y1="6" x2="12" y2="8"/>
                <line x1="12" y1="16" x2="12" y2="18"/>
            </svg>
        </div>
        <span class="brand-name">Sentimo</span>
    </div>

    <div class="card">
        <div class="card-title">Create Account</div>
        <div class="card-sub">Your Personal Finance Tracker</div>

        <?php if ($message === 'success'): ?>
        <div class="alert success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Registration successful! You can now login.
        </div>
        <?php elseif ($message === 'taken'): ?>
        <div class="alert danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Username already taken!
        </div>
        <?php elseif ($message === 'empty'): ?>
        <div class="alert danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Please fill in all fields.
        </div>
        <?php elseif ($message === 'error'): ?>
        <div class="alert danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            An error occurred. Please try again.
        </div>
        <?php endif; ?>

        <form action="register.php" method="POST" autocomplete="off">
            <div class="field">
                <label>Username</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" name="username" placeholder="Enter your username" required>
                </div>
            </div>
            <div class="field">
                <label>Password</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="password" id="pw-input" placeholder="Enter your password" required>
                    <button type="button" class="pw-toggle" onclick="togglePw()" title="Show/hide password">
                        <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="submit-btn">Register</button>
        </form>

        <div class="card-footer">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>

    <div class="tagline">Track every peso. Build every dream.</div>

</div>

<script>
function togglePw() {
    const inp = document.getElementById('pw-input');
    const ico = document.getElementById('eye-icon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        inp.type = 'password';
        ico.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}
</script>
</body>
</html>