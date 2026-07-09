<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id      = $_SESSION['user_id'];
$username     = $_SESSION['username'];
$display_name = $_SESSION['display_name'] ?? $username;

// ── PRG: read + clear flash message from session ───────────────────────
$message = '';
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// 1. UPDATE PROFILE (display name)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_display_name = trim($_POST['display_name']);
    if ($new_display_name !== '') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
            $stmt->execute([$new_display_name, $user_id]);
            $_SESSION['display_name']    = $new_display_name;
            $_SESSION['success_message'] = 'profile_success';
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:Could not update profile.';
        }
    } else {
        $_SESSION['success_message'] = 'error:Display name cannot be empty.';
    }
    header("Location: settings.php");
    exit;
}

// 2. CHANGE PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_password'];
    $new_pw   = $_POST['new_password'];
    $confirm  = $_POST['confirm_password'];

    if (empty($current) || empty($new_pw) || empty($confirm)) {
        $_SESSION['success_message'] = 'error:Please fill in all password fields.';
    } elseif ($new_pw !== $confirm) {
        $_SESSION['success_message'] = 'error:New password and confirmation do not match.';
    } elseif (strlen($new_pw) < 8) {
        $_SESSION['success_message'] = 'error:New password must be at least 8 characters.';
    } else {
        try {
            $check = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $check->execute([$user_id]);
            $row = $check->fetch();
            if ($row && password_verify($current, $row['password'])) {
                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->execute([$hashed, $user_id]);
                $_SESSION['success_message'] = 'password_success';
            } else {
                $_SESSION['success_message'] = 'error:Current password is incorrect.';
            }
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:Could not update password.';
        }
    }
    header("Location: settings.php");
    exit;
}

// 3. ADD CATEGORY (global — affects all users)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $cat_name = trim($_POST['category_name']);
    $cat_type = $_POST['category_type'];

    if ($cat_name !== '' && in_array($cat_type, ['income', 'expense'], true)) {
        try {
            $dupe = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND type = ?");
            $dupe->execute([$cat_name, $cat_type]);
            if ($dupe->fetch()) {
                $_SESSION['success_message'] = 'error:That category already exists.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name, type) VALUES (?, ?)");
                $stmt->execute([$cat_name, $cat_type]);
                $_SESSION['success_message'] = 'category_added';
            }
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:Could not add category.';
        }
    } else {
        $_SESSION['success_message'] = 'error:Please provide a valid category name and type.';
    }
    header("Location: settings.php");
    exit;
}

// 4. RENAME CATEGORY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_category'])) {
    $cat_id   = (int) $_POST['category_id'];
    $new_name = trim($_POST['new_name']);
    if ($cat_id > 0 && $new_name !== '') {
        try {
            $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->execute([$new_name, $cat_id]);
            $_SESSION['success_message'] = 'category_renamed';
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:Could not rename category.';
        }
    }
    header("Location: settings.php");
    exit;
}

// 5. DELETE CATEGORY (blocked if any transactions still reference it — global safety check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $cat_id = (int) $_POST['category_id'];
    if ($cat_id > 0) {
        try {
            $usage = $pdo->prepare("SELECT COUNT(*) AS cnt FROM transactions WHERE category_id = ?");
            $usage->execute([$cat_id]);
            $in_use = (int) $usage->fetch()['cnt'];
            if ($in_use > 0) {
                $_SESSION['success_message'] = 'error:This category is used by ' . $in_use . ' transaction(s) and can\'t be deleted.';
            } else {
                $del = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                $del->execute([$cat_id]);
                $_SESSION['success_message'] = 'category_deleted';
            }
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:Could not delete category.';
        }
    }
    header("Location: settings.php");
    exit;
}

// ── FETCH CATEGORIES (global list, with usage counts) ───────────────────
$cat_stmt = $pdo->query("
    SELECT c.id, c.name, c.type, COUNT(t.id) AS usage_count
    FROM categories c
    LEFT JOIN transactions t ON t.category_id = c.id
    GROUP BY c.id, c.name, c.type
    ORDER BY c.type, c.name
");
$all_categories  = $cat_stmt->fetchAll();
$income_cats_all = array_filter($all_categories, fn($c) => $c['type'] === 'income');
$expense_cats_all = array_filter($all_categories, fn($c) => $c['type'] === 'expense');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Sentimo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    /* ─── TOKENS ─────────────────────────────────────────────────────── */
    :root {
        --bg:          #080E1C;
        --bg2:         #0D1426;
        --surface:     rgba(255,255,255,.04);
        --surface-h:   rgba(255,255,255,.07);
        --border:      rgba(255,255,255,.08);
        --border-h:    rgba(59,130,246,.4);
        --blue:        #3B82F6;
        --blue-light:  #60A5FA;
        --blue-glow:   rgba(59,130,246,.25);
        --blue-dim:    rgba(59,130,246,.12);
        --indigo:      #818CF8;
        --sky:         #38BDF8;
        --violet:      #A78BFA;
        --income-clr:  #34D399;
        --income-bg:   rgba(52,211,153,.12);
        --expense-clr: #F87171;
        --expense-bg:  rgba(248,113,113,.12);
        --text-1:  #F1F5F9;
        --text-2:  #94A3B8;
        --text-3:  #475569;
        --r-sm: 8px; --r-md: 14px; --r-lg: 20px; --r-xl: 28px;
        --font: 'Inter', system-ui, sans-serif;
    }

    /* ─── LIGHT MODE ─────────────────────────────────────────────────── */
    [data-theme="light"] {
        --bg:          #F0F4FF;
        --bg2:         #E8EEFB;
        --surface:     rgba(255,255,255,.75);
        --surface-h:   rgba(255,255,255,.95);
        --border:      rgba(30,64,175,.12);
        --border-h:    rgba(59,130,246,.5);
        --text-1:      #0F172A;
        --text-2:      #475569;
        --text-3:      #94A3B8;
        --income-bg:   rgba(5,150,105,.12);
        --expense-bg:  rgba(220,38,38,.10);
        --blue-dim:    rgba(59,130,246,.10);
        --blue-glow:   rgba(59,130,246,.2);
    }
    [data-theme="light"] body { background: #F0F4FF; }
    [data-theme="light"] body::before { background: radial-gradient(circle, rgba(59,130,246,.18) 0%, rgba(99,102,241,.08) 40%, transparent 70%); }
    [data-theme="light"] body::after  { background: radial-gradient(circle, rgba(56,189,248,.12) 0%, rgba(129,140,248,.08) 40%, transparent 70%); }
    [data-theme="light"] .bg-mid-glow { background: radial-gradient(ellipse, rgba(99,102,241,.05) 0%, transparent 65%); }
    [data-theme="light"] .sidebar { background: rgba(255,255,255,.92); border-right-color: rgba(30,64,175,.1); }
    [data-theme="light"] .sb-btn { color: #94A3B8; }
    [data-theme="light"] .sb-btn:hover { background: rgba(59,130,246,.08); color: #475569; }
    [data-theme="light"] .sb-btn.active { background: rgba(59,130,246,.1); color: var(--blue); }
    [data-theme="light"] .sb-btn.active::before { background: var(--blue); }
    [data-theme="light"] .sb-brand { color: #0F172A; }
    [data-theme="light"] .sb-divider { background: rgba(30,64,175,.1); }
    [data-theme="light"] .sb-avatar-name { color: #475569; }
    [data-theme="light"] .panel { background: rgba(255,255,255,.85); border-color: rgba(30,64,175,.1); box-shadow: 0 2px 12px rgba(15,23,42,.07); }
    [data-theme="light"] .panel-head h2 { color: #0F172A; }
    [data-theme="light"] .page-greeting { color: #475569; }
    [data-theme="light"] .page-greeting span { color: #0F172A; }
    [data-theme="light"] .notif-dot { border-color: #F0F4FF; }
    [data-theme="light"] .theme-toggle { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .notif-btn { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .topbar-avatar { background: linear-gradient(135deg, var(--blue), var(--indigo)); }
    [data-theme="light"] .field input, [data-theme="light"] .field select { background: rgba(255,255,255,.7); color: #0F172A; }
    [data-theme="light"] .field input::placeholder { color: #94A3B8; }
    [data-theme="light"] .cat-row { border-color: rgba(30,64,175,.08); }
    [data-theme="light"] .cat-row:hover { background: rgba(59,130,246,.04); }
    [data-theme="light"] .pref-row { border-color: rgba(30,64,175,.08); }
    [data-theme="light"] select option { background: #ffffff !important; color: #0F172A !important; }

    /* ─── THEME TOGGLE (topbar icon) ─────────────────────────────────── */
    .theme-toggle {
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--surface); border: 1px solid var(--border);
        display: grid; place-items: center; cursor: pointer;
        transition: background .15s, transform .2s; flex-shrink: 0;
        color: var(--text-2);
    }
    .theme-toggle:hover { background: var(--surface-h); color: var(--text-1); transform: rotate(15deg); }
    .theme-toggle svg   { width: 16px; height: 16px; }
    .theme-toggle .icon-sun  { display: none; }
    .theme-toggle .icon-moon { display: block; }
    [data-theme="light"] .theme-toggle .icon-sun  { display: block; }
    [data-theme="light"] .theme-toggle .icon-moon { display: none; }

    /* ─── RESET ──────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 14px; }
    body {
        font-family: var(--font); background: var(--bg); color: var(--text-1);
        min-height: 100vh; display: flex; overflow-x: hidden;
    }
    body::before {
        content: ''; position: fixed; top: -180px; left: 30px;
        width: 800px; height: 800px;
        background: radial-gradient(circle, rgba(59,130,246,.22) 0%, rgba(99,102,241,.10) 40%, transparent 70%);
        pointer-events: none; z-index: 0;
    }
    body::after {
        content: ''; position: fixed; bottom: -80px; right: -60px;
        width: 680px; height: 680px;
        background: radial-gradient(circle, rgba(56,189,248,.15) 0%, rgba(129,140,248,.10) 40%, transparent 70%);
        pointer-events: none; z-index: 0;
    }
    .bg-mid-glow {
        position: fixed; top: 40%; left: 50%; transform: translate(-50%,-50%);
        width: 900px; height: 500px;
        background: radial-gradient(ellipse, rgba(99,102,241,.07) 0%, transparent 65%);
        pointer-events: none; z-index: 0;
    }

    /* ─── EXPANDABLE SIDEBAR ─────────────────────────────────────────── */
    .sidebar {
        width: 72px; min-height: 100vh;
        background: rgba(13,20,38,.92); backdrop-filter: blur(24px);
        border-right: 1px solid var(--border);
        display: flex; flex-direction: column; align-items: flex-start;
        padding: 20px 0 24px; position: fixed; top: 0; left: 0; z-index: 200;
        gap: 0; overflow: hidden;
        transition: width .28s cubic-bezier(.4,0,.2,1), box-shadow .28s ease;
    }
    .sidebar:hover { width: 210px; box-shadow: 4px 0 32px rgba(0,0,0,.45); border-right-color: var(--border-h); }
    .sb-logo { width: 40px; height: 40px; border-radius: var(--r-sm); display: grid; place-items: center; flex-shrink: 0; overflow: hidden; }
    .sb-logo-inner { width: 40px; height: 40px; background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%); border-radius: var(--r-sm); display: flex; align-items: center; justify-content: center; }
    .sb-logo-text { font-size: .68rem; font-weight: 800; color: #fff; letter-spacing: -.02em; line-height: 1; text-align: center; font-family: var(--font); }
    .sb-logo-row { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; padding: 0 16px; flex-shrink: 0; width: 100%; }
    .sb-brand { white-space: nowrap; font-size: .88rem; font-weight: 800; color: var(--text-1); letter-spacing: -.03em; opacity: 0; transform: translateX(-8px); transition: opacity .22s ease .06s, transform .22s ease .06s; pointer-events: none; }
    .sidebar:hover .sb-brand { opacity: 1; transform: translateX(0); }
    .sb-nav { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; flex: 1; width: 100%; padding: 0 12px; }
    .sb-btn { width: 100%; height: 44px; border-radius: var(--r-sm); display: flex; align-items: center; gap: 12px; padding: 0 10px; color: var(--text-3); cursor: pointer; transition: background .15s, color .15s; text-decoration: none; position: relative; white-space: nowrap; flex-shrink: 0; }
    .sb-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
    .sb-btn-label { font-size: .82rem; font-weight: 600; opacity: 0; transform: translateX(-8px); transition: opacity .18s ease .04s, transform .18s ease .04s; pointer-events: none; }
    .sidebar:hover .sb-btn-label { opacity: 1; transform: translateX(0); }
    .sb-btn:hover { background: var(--surface-h); color: var(--text-2); }
    .sb-btn.active { background: var(--blue-dim); color: var(--blue-light); }
    .sb-btn.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 20px; background: var(--blue); border-radius: 0 3px 3px 0; }
    .sb-divider { width: calc(100% - 24px); height: 1px; background: var(--border); margin: 10px 12px; flex-shrink: 0; }
    .sb-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%); display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: #fff; cursor: pointer; margin-top: auto; margin-left: 18px; flex-shrink: 0; transition: box-shadow .15s; position: relative; }
    .sb-avatar:hover { box-shadow: 0 0 0 3px var(--blue-glow); }
    .sb-avatar-name { position: absolute; left: 48px; white-space: nowrap; font-size: .8rem; font-weight: 600; color: var(--text-1); opacity: 0; transform: translateX(-6px); transition: opacity .18s ease .04s, transform .18s ease .04s; pointer-events: none; }
    .sidebar:hover .sb-avatar-name { opacity: 1; transform: translateX(0); }

    /* ─── MAIN WRAPPER ───────────────────────────────────────────────── */
    .main { margin-left: 72px; flex: 1; display: flex; flex-direction: column; position: relative; z-index: 1; transition: margin-left .28s cubic-bezier(.4,0,.2,1); }
    .canvas { padding: 18px 20px 40px; display: flex; flex-direction: column; gap: 16px; max-width: 880px; }

    /* ─── PAGE TOP ROW ───────────────────────────────────────────────── */
    .page-top-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
    .page-heading h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -.04em; }
    .page-heading p  { font-size: .82rem; color: var(--text-2); margin-top: 4px; }
    .page-top-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .page-greeting { font-size: .85rem; font-weight: 600; color: var(--text-2); white-space: nowrap; }
    .page-greeting span { color: var(--text-1); }
    .notif-btn { width: 36px; height: 36px; border-radius: 50%; background: var(--surface); border: 1px solid var(--border); display: grid; place-items: center; cursor: pointer; position: relative; transition: background .15s; }
    .notif-btn:hover { background: var(--surface-h); }
    .notif-btn svg { width: 16px; height: 16px; color: var(--text-2); }
    .notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; background: var(--blue); border-radius: 50%; border: 1.5px solid var(--bg); }
    .topbar-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--blue), var(--indigo)); display: grid; place-items: center; font-size: .78rem; font-weight: 700; color: #fff; cursor: pointer; }

    /* ─── TOAST ──────────────────────────────────────────────────────── */
    .toast { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--r-md); font-size: .8rem; font-weight: 500; animation: fadeDown .25s ease; }
    .toast.success { background: var(--income-bg); color: var(--income-clr); border: 1px solid rgba(52,211,153,.25); }
    .toast.error   { background: var(--expense-bg); color: var(--expense-clr); border: 1px solid rgba(248,113,113,.25); }
    .toast svg     { width: 15px; height: 15px; flex-shrink: 0; }
    @keyframes fadeDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

    /* ─── GLASS PANEL ────────────────────────────────────────────────── */
    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); backdrop-filter: blur(12px); overflow: hidden; }
    .panel-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--border); }
    .panel-head h2 { font-size: .92rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .panel-head h2 svg { width: 16px; height: 16px; color: var(--blue-light); }
    .panel-head p  { font-size: .72rem; color: var(--text-2); margin-top: 2px; }
    .panel-body { padding: 18px 20px; }

    /* ─── PROFILE HEADER ─────────────────────────────────────────────── */
    .profile-strip { display: flex; align-items: center; gap: 16px; padding: 20px; }
    .profile-avatar { width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%); display: grid; place-items: center; font-size: 1.4rem; font-weight: 800; color: #fff; flex-shrink: 0; }
    .profile-meta h3 { font-size: 1.05rem; font-weight: 700; }
    .profile-meta p  { font-size: .78rem; color: var(--text-2); margin-top: 2px; }

    /* ─── FORM FIELDS ────────────────────────────────────────────────── */
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: .68rem; font-weight: 600; color: var(--text-2); margin-bottom: 5px; letter-spacing: .04em; text-transform: uppercase; }
    .field input, .field select {
        width: 100%; padding: 9px 12px;
        background: rgba(255,255,255,.05);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        color: var(--text-1);
        font-family: var(--font); font-size: .85rem;
        outline: none;
        transition: border-color .15s, box-shadow .15s;
    }
    .field input:disabled { color: var(--text-3); cursor: not-allowed; }
    .field input::placeholder { color: var(--text-3); }
    .field input:focus, .field select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-glow); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .field-hint { font-size: .68rem; color: var(--text-3); margin-top: -6px; margin-bottom: 12px; }

    .btn-primary {
        padding: 9px 20px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border: none; border-radius: var(--r-sm);
        color: #fff; font-family: var(--font); font-size: .82rem; font-weight: 700;
        cursor: pointer; transition: opacity .15s, box-shadow .15s;
    }
    .btn-primary:hover { opacity: .9; box-shadow: 0 6px 20px var(--blue-glow); }
    .btn-primary:active { transform: scale(.98); }

    /* ─── PREFERENCE ROW (theme toggle switch) ───────────────────────── */
    .pref-row { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
    .pref-row:last-child { border-bottom: none; }
    .pref-label { font-size: .84rem; font-weight: 600; }
    .pref-sub { font-size: .72rem; color: var(--text-2); margin-top: 2px; }

    .switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch-track {
        position: absolute; inset: 0; background: var(--border);
        border-radius: 24px; cursor: pointer; transition: background .2s;
    }
    .switch-track::before {
        content: ''; position: absolute; width: 18px; height: 18px;
        left: 3px; top: 3px; background: #fff; border-radius: 50%;
        transition: transform .2s;
    }
    .switch input:checked + .switch-track { background: var(--blue); }
    .switch input:checked + .switch-track::before { transform: translateX(20px); }

    /* ─── CATEGORY MANAGER ───────────────────────────────────────────── */
    .cat-add-row { display: flex; gap: 8px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
    .cat-add-row input { flex: 1; }
    .cat-add-row select { width: 130px; flex-shrink: 0; }
    .cat-add-row button { flex-shrink: 0; }

    .cat-group-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); padding: 12px 20px 6px; }
    .cat-row { display: flex; align-items: center; gap: 10px; padding: 10px 20px; border-bottom: 1px solid var(--border); }
    .cat-row:last-child { border-bottom: none; }
    .cat-row:hover { background: var(--surface-h); }
    .cat-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .cat-dot.income { background: var(--income-clr); }
    .cat-dot.expense { background: var(--expense-clr); }
    .cat-name-form { flex: 1; display: flex; align-items: center; gap: 8px; }
    .cat-name-input {
        flex: 1; background: transparent; border: 1px solid transparent;
        border-radius: var(--r-sm); padding: 5px 8px;
        color: var(--text-1); font-family: var(--font); font-size: .82rem;
        outline: none; transition: border-color .15s, background .15s;
    }
    .cat-name-input:hover { border-color: var(--border); }
    .cat-name-input:focus { border-color: var(--blue); background: rgba(255,255,255,.05); }
    .cat-usage { font-size: .68rem; color: var(--text-3); white-space: nowrap; flex-shrink: 0; }
    .cat-save-btn, .cat-del-btn {
        width: 26px; height: 26px; border-radius: var(--r-sm);
        background: transparent; border: 1px solid transparent;
        display: grid; place-items: center; cursor: pointer;
        color: var(--text-3); flex-shrink: 0; padding: 0;
        transition: background .15s, color .15s, border-color .15s;
    }
    .cat-save-btn svg, .cat-del-btn svg { width: 13px; height: 13px; }
    .cat-save-btn:hover { background: var(--income-bg) !important; color: var(--income-clr) !important; }
    .cat-del-btn:hover:not(:disabled) { background: var(--expense-bg) !important; color: var(--expense-clr) !important; }
    .cat-del-btn:disabled { opacity: .25; cursor: not-allowed; }
    .cat-empty { padding: 20px; text-align: center; color: var(--text-3); font-size: .8rem; }

    @media (max-width: 640px) {
        .sidebar { display: none; }
        .main { margin-left: 0; }
        .canvas { padding: 16px; max-width: 100%; }
        .field-row { grid-template-columns: 1fr; }
        .cat-add-row { flex-wrap: wrap; }
        .cat-add-row select { width: 100%; }
    }
    </style>
</head>
<body>

<!-- ═══ SIDEBAR ═════════════════════════════════════════════════════ -->
<aside class="sidebar">
    <div class="sb-logo-row">
        <div class="sb-logo">
            <div class="sb-logo-inner"><span class="sb-logo-text">Se</span></div>
        </div>
        <span class="sb-brand">Sentimo</span>
    </div>
    <nav class="sb-nav">
        <a href="dashboard.php" class="sb-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span class="sb-btn-label">Dashboard</span>
        </a>
        <a href="transactions.php" class="sb-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            <span class="sb-btn-label">Transactions</span>
        </a>
        <a href="reports.php" class="sb-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span class="sb-btn-label">Reports</span>
        </a>
        <div class="sb-divider"></div>
        <a href="notifications.php" class="sb-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="sb-btn-label">Notifications</span>
        </a>
        <a href="settings.php" class="sb-btn active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06-.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span class="sb-btn-label">Settings</span>
        </a>
        <div class="sb-divider"></div>
        <a href="logout.php" class="sb-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span class="sb-btn-label">Logout</span>
        </a>
    </nav>
    <div class="sb-avatar" title="<?php echo htmlspecialchars($display_name); ?>">
        <?php echo strtoupper(substr($display_name, 0, 1)); ?>
        <span class="sb-avatar-name"><?php echo htmlspecialchars($display_name); ?></span>
    </div>
</aside>

<div class="bg-mid-glow"></div>

<!-- ═══ MAIN ════════════════════════════════════════════════════════ -->
<div class="main">
<div class="canvas">

    <!-- Page top row -->
    <div class="page-top-row">
        <div class="page-heading">
            <h1>Settings</h1>
            <p>Manage your profile, preferences, and categories</p>
        </div>
        <div class="page-top-actions">
            <span class="page-greeting">Hi, <span><?php echo htmlspecialchars($display_name); ?>!</span></span>
            <button class="theme-toggle" id="themeToggle" title="Toggle light/dark mode" type="button">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>
            <div class="notif-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar"><?php echo strtoupper(substr($display_name, 0, 1)); ?></div>
        </div>
    </div>

    <!-- Toast Alerts (PRG session-based) -->
    <?php if ($message === 'profile_success'): ?>
    <div class="toast success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Profile updated successfully.
    </div>
    <?php elseif ($message === 'password_success'): ?>
    <div class="toast success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Password changed successfully.
    </div>
    <?php elseif ($message === 'category_added'): ?>
    <div class="toast success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Category added.
    </div>
    <?php elseif ($message === 'category_renamed'): ?>
    <div class="toast success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Category renamed.
    </div>
    <?php elseif ($message === 'category_deleted'): ?>
    <div class="toast success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Category deleted.
    </div>
    <?php elseif (str_starts_with($message, 'error:')): ?>
    <div class="toast error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php echo htmlspecialchars(substr($message, 6)); ?>
    </div>
    <?php endif; ?>

    <!-- ═══ PROFILE ═══════════════════════════════════════════════════ -->
    <div class="panel">
        <div class="profile-strip">
            <div class="profile-avatar"><?php echo strtoupper(substr($display_name, 0, 1)); ?></div>
            <div class="profile-meta">
                <h3><?php echo htmlspecialchars($display_name); ?></h3>
                <p>@<?php echo htmlspecialchars($username); ?></p>
            </div>
        </div>
        <div class="panel-head">
            <div>
                <h2>Profile</h2>
                <p>Update how your name appears across Sentimo</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="POST" action="settings.php">
                <div class="field">
                    <label>Display Name</label>
                    <input type="text" name="display_name" value="<?php echo htmlspecialchars($display_name); ?>" required maxlength="60">
                </div>
                <div class="field">
                    <label>Username</label>
                    <input type="text" value="<?php echo htmlspecialchars($username); ?>" disabled>
                </div>
                <div class="field-hint">Username can't be changed.</div>
                <button type="submit" name="update_profile" class="btn-primary">Save Profile</button>
            </form>
        </div>

        <div class="panel-head" style="border-top:1px solid var(--border);">
            <div>
                <h2>Change Password</h2>
                <p>Use a strong password you don't use elsewhere</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="POST" action="settings.php">
                <div class="field">
                    <label>Current Password</label>
                    <input type="password" name="current_password" placeholder="••••••••" required>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="At least 8 characters" required minlength="8">
                    </div>
                    <div class="field">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" placeholder="Re-enter new password" required minlength="8">
                    </div>
                </div>
                <button type="submit" name="change_password" class="btn-primary">Update Password</button>
            </form>
        </div>
    </div>

    <!-- ═══ PREFERENCES ═══════════════════════════════════════════════ -->
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Preferences</h2>
                <p>Personalize how Sentimo looks</p>
            </div>
        </div>
        <div class="pref-row">
            <div>
                <div class="pref-label">Dark Mode</div>
                <div class="pref-sub">Switch between light and dark theme</div>
            </div>
            <label class="switch">
                <input type="checkbox" id="themeSwitch">
                <span class="switch-track"></span>
            </label>
        </div>
    </div>

    <!-- ═══ CATEGORIES MANAGER ════════════════════════════════════════ -->
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    Categories
                </h2>
                <p>Shared across all Sentimo accounts — used to tag income and expenses</p>
            </div>
        </div>

        <!-- Add new category -->
        <form method="POST" action="settings.php" class="cat-add-row">
            <input type="text" name="category_name" placeholder="New category name…" required maxlength="40">
            <select name="category_type" required>
                <option value="expense">Expense</option>
                <option value="income">Income</option>
            </select>
            <button type="submit" name="add_category" class="btn-primary">Add</button>
        </form>

        <?php if (empty($all_categories)): ?>
            <div class="cat-empty">No categories yet. Add one above to get started.</div>
        <?php else: ?>

            <div class="cat-group-label">Income</div>
            <?php if (empty($income_cats_all)): ?>
                <div class="cat-empty">No income categories yet.</div>
            <?php endif; ?>
            <?php foreach ($income_cats_all as $c): ?>
            <div class="cat-row">
                <span class="cat-dot income"></span>
                <form method="POST" action="settings.php" class="cat-name-form">
                    <input type="hidden" name="category_id" value="<?php echo (int) $c['id']; ?>">
                    <input type="text" name="new_name" class="cat-name-input" value="<?php echo htmlspecialchars($c['name']); ?>" maxlength="40">
                    <span class="cat-usage"><?php echo (int) $c['usage_count']; ?> use<?php echo $c['usage_count'] == 1 ? '' : 's'; ?></span>
                    <button type="submit" name="rename_category" class="cat-save-btn" title="Save name">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                </form>
                <form method="POST" action="settings.php" onsubmit="return confirm('Delete this category?');">
                    <input type="hidden" name="category_id" value="<?php echo (int) $c['id']; ?>">
                    <button type="submit" name="delete_category" class="cat-del-btn" title="<?php echo $c['usage_count'] > 0 ? 'In use — remove its transactions first' : 'Delete category'; ?>" <?php echo $c['usage_count'] > 0 ? 'disabled' : ''; ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>

            <div class="cat-group-label">Expense</div>
            <?php if (empty($expense_cats_all)): ?>
                <div class="cat-empty">No expense categories yet.</div>
            <?php endif; ?>
            <?php foreach ($expense_cats_all as $c): ?>
            <div class="cat-row">
                <span class="cat-dot expense"></span>
                <form method="POST" action="settings.php" class="cat-name-form">
                    <input type="hidden" name="category_id" value="<?php echo (int) $c['id']; ?>">
                    <input type="text" name="new_name" class="cat-name-input" value="<?php echo htmlspecialchars($c['name']); ?>" maxlength="40">
                    <span class="cat-usage"><?php echo (int) $c['usage_count']; ?> use<?php echo $c['usage_count'] == 1 ? '' : 's'; ?></span>
                    <button type="submit" name="rename_category" class="cat-save-btn" title="Save name">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                </form>
                <form method="POST" action="settings.php" onsubmit="return confirm('Delete this category?');">
                    <input type="hidden" name="category_id" value="<?php echo (int) $c['id']; ?>">
                    <button type="submit" name="delete_category" class="cat-del-btn" title="<?php echo $c['usage_count'] > 0 ? 'In use — remove its transactions first' : 'Delete category'; ?>" <?php echo $c['usage_count'] > 0 ? 'disabled' : ''; ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>

</div>
</div>

<script>
// ── Dark / Light Mode: topbar icon + Preferences switch, kept in sync ──
(function () {
    const root      = document.documentElement;
    const iconBtn   = document.getElementById('themeToggle');
    const switchBox = document.getElementById('themeSwitch');
    const STORAGE   = 'sentimo_theme';

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        switchBox.checked = (theme === 'light');
        localStorage.setItem(STORAGE, theme);
    }

    apply(localStorage.getItem(STORAGE) || 'dark');

    iconBtn.addEventListener('click', function () {
        apply(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
    switchBox.addEventListener('change', function () {
        apply(switchBox.checked ? 'light' : 'dark');
    });
})();

// ── Theme toggle (Settings page — single source of truth) ────────────
(function () {
    const root    = document.documentElement;
    const STORAGE = 'sentimo_theme';
    const saved   = localStorage.getItem(STORAGE) || 'dark';
    root.setAttribute('data-theme', saved);

    // Sync the toggle switch UI on load
    const toggle = document.getElementById('darkModeToggle');
    if (toggle) {
        toggle.checked = (saved === 'dark');
        toggle.addEventListener('change', function () {
            const next = toggle.checked ? 'dark' : 'light';
            root.setAttribute('data-theme', next);
            localStorage.setItem(STORAGE, next);
        });
    }
})();
</script>
</body>
</html>
