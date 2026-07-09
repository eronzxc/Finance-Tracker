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

// ── PRG: flash messages ──────────────────────────────────────────────
$message = '';
if (isset($_SESSION['tx_message'])) {
    $message = $_SESSION['tx_message'];
    unset($_SESSION['tx_message']);
}

// ── CATEGORIES ───────────────────────────────────────────────────────
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY type, name");
$categories      = $categories_stmt->fetchAll();
$income_cats     = array_filter($categories, fn($c) => $c['type'] === 'income');
$expense_cats    = array_filter($categories, fn($c) => $c['type'] === 'expense');

// ── DELETE ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_transaction'])) {
    $del_id = (int) $_POST['transaction_id'];
    if ($del_id > 0) {
        try {
            $s = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
            $s->execute([$del_id, $user_id]);
            $_SESSION['tx_message'] = 'delete_success';
        } catch (PDOException $e) {
            $_SESSION['tx_message'] = 'error:Could not delete transaction.';
        }
    }
    header("Location: transactions.php");
    exit;
}

// ── EDIT ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_transaction'])) {
    $edit_id     = (int)   $_POST['transaction_id'];
    $amount      = (float) $_POST['amount'];
    $category_id = (int)   $_POST['category_id'];
    $description = trim($_POST['description']);
    $date        = $_POST['date'];

    if ($edit_id > 0 && $amount > 0 && $category_id > 0 && !empty($date)) {
        try {
            $s = $pdo->prepare("UPDATE transactions SET category_id=?, amount=?, description=?, date=? WHERE id=? AND user_id=?");
            $s->execute([$category_id, $amount, $description, $date, $edit_id, $user_id]);
            $_SESSION['tx_message'] = 'edit_success';
        } catch (PDOException $e) {
            $_SESSION['tx_message'] = 'error:' . $e->getMessage();
        }
    } else {
        $_SESSION['tx_message'] = 'error:Please fill in all required fields.';
    }
    header("Location: transactions.php");
    exit;
}

// ── ADD ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $amount      = (float) $_POST['amount'];
    $category_id = (int)   $_POST['category_id'];
    $description = trim($_POST['description']);
    $date        = $_POST['date'];

    if ($amount > 0 && $category_id > 0 && !empty($date)) {
        try {
            $s = $pdo->prepare("INSERT INTO transactions (user_id, category_id, amount, description, date) VALUES (?, ?, ?, ?, ?)");
            $s->execute([$user_id, $category_id, $amount, $description, $date]);
            $_SESSION['tx_message'] = 'add_success';
        } catch (PDOException $e) {
            $_SESSION['tx_message'] = 'error:' . $e->getMessage();
        }
    } else {
        $_SESSION['tx_message'] = 'error:Please fill in all required fields.';
    }
    header("Location: transactions.php");
    exit;
}

// ── TOTALS ────────────────────────────────────────────────────────────
$inc_stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'income'");
$inc_stmt->execute([$user_id]);
$total_income = $inc_stmt->fetch()['total'] ?? 0;

$exp_stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'expense'");
$exp_stmt->execute([$user_id]);
$total_expense = $exp_stmt->fetch()['total'] ?? 0;

$current_balance = $total_income - $total_expense;

// ── ALL TRANSACTIONS ──────────────────────────────────────────────────
$trans_stmt = $pdo->prepare("
    SELECT t.*, c.name AS category_name, c.type
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ?
    ORDER BY t.date DESC, t.id DESC
");
$trans_stmt->execute([$user_id]);
$transactions = $trans_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions — FinanceTracker</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    /* ─── TOKENS (same as dashboard) ─────────────────────────────────── */
    :root {
        --bg:          #080E1C;
        --surface:     rgba(255,255,255,.04);
        --surface-h:   rgba(255,255,255,.07);
        --border:      rgba(255,255,255,.08);
        --border-h:    rgba(59,130,246,.4);
        --blue:        #3B82F6;
        --blue-light:  #60A5FA;
        --blue-glow:   rgba(59,130,246,.25);
        --blue-dim:    rgba(59,130,246,.12);
        --indigo:      #818CF8;
        --income-clr:  #34D399;
        --income-bg:   rgba(52,211,153,.12);
        --expense-clr: #F87171;
        --expense-bg:  rgba(248,113,113,.12);
        --text-1: #F1F5F9;
        --text-2: #94A3B8;
        --text-3: #475569;
        --r-sm: 8px;
        --r-md: 14px;
        --r-lg: 20px;
        --font: 'Inter', system-ui, sans-serif;
    }

    /* ─── RESET ──────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 14px; }
    body {
        font-family: var(--font);
        color: var(--text-1);
        min-height: 100vh;
        display: flex;
        overflow-x: hidden;
        background:
            radial-gradient(ellipse 80% 60% at 10% 0%,   rgba(29,78,216,.55)   0%, transparent 55%),
            radial-gradient(ellipse 60% 50% at 90% 100%,  rgba(99,102,241,.40)  0%, transparent 55%),
            radial-gradient(ellipse 50% 40% at 60% 40%,   rgba(14,116,144,.25)  0%, transparent 50%),
            #070C18;
        background-attachment: fixed;
    }
    body::before {
        content: '';
        position: fixed; inset: 0;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
        pointer-events: none; z-index: 0;
    }

        .bg-mid-glow {
        position: fixed;
        top: 40%; left: 50%;
        transform: translate(-50%, -50%);
        width: 900px; height: 500px;
        background: radial-gradient(ellipse, rgba(99,102,241,.07) 0%, transparent 65%);
        pointer-events: none;
        z-index: 0;
    }

    /* ─── EXPANDABLE SIDEBAR ─────────────────────────────────────────── */
    .sidebar {
        width: 72px;
        min-height: 100vh;
        background: rgba(13,20,38,.92);
        backdrop-filter: blur(24px);
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        padding: 20px 0 24px;
        position: fixed;
        top: 0; left: 0;
        z-index: 200;
        gap: 0;
        overflow: hidden;
        transition: width .28s cubic-bezier(.4,0,.2,1),
                    box-shadow .28s ease;
    }
    .sidebar:hover {
        width: 210px;
        box-shadow: 4px 0 32px rgba(0,0,0,.45);
        border-right-color: var(--border-h);
    }

    /* ── Logo area ── */
    .sb-logo {
        width: 40px; height: 40px;
        border-radius: var(--r-sm);
        display: grid; place-items: center;
        flex-shrink: 0;
        overflow: hidden;
    }
    .sb-logo-inner {
        width: 40px; height: 40px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border-radius: var(--r-sm);
        display: flex; align-items: center; justify-content: center;
    }
    .sb-logo-text {
        font-size: .68rem; font-weight: 800;
        color: #fff; letter-spacing: -.02em;
        line-height: 1; text-align: center;
        font-family: var(--font);
    }
    /* Logo row wraps icon + brand name */
    .sb-logo-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 32px;
        padding: 0 16px;
        flex-shrink: 0;
        width: 100%;
    }
    /* App name shown beside logo on expand */
    .sb-brand {
        white-space: nowrap;
        font-size: .88rem; font-weight: 800;
        color: var(--text-1);
        letter-spacing: -.03em;
        opacity: 0;
        transform: translateX(-8px);
        transition: opacity .22s ease .06s, transform .22s ease .06s;
        pointer-events: none;
    }
    .sidebar:hover .sb-brand {
        opacity: 1;
        transform: translateX(0);
    }

    /* ── Nav ── */
    .sb-nav {
        display: flex; flex-direction: column;
        align-items: flex-start;
        gap: 4px; flex: 1;
        width: 100%;
        padding: 0 12px;
    }
    .sb-btn {
        width: 100%;
        height: 44px;
        border-radius: var(--r-sm);
        display: flex; align-items: center;
        gap: 12px;
        padding: 0 10px;
        color: var(--text-3);
        cursor: pointer;
        transition: background .15s, color .15s;
        text-decoration: none;
        position: relative;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .sb-btn svg {
        width: 18px; height: 18px;
        flex-shrink: 0;
    }
    .sb-btn-label {
        font-size: .82rem; font-weight: 600;
        opacity: 0;
        transform: translateX(-8px);
        transition: opacity .18s ease .04s, transform .18s ease .04s;
        pointer-events: none;
    }
    .sidebar:hover .sb-btn-label {
        opacity: 1;
        transform: translateX(0);
    }
    .sb-btn:hover { background: var(--surface-h); color: var(--text-2); }
    .sb-btn.active {
        background: var(--blue-dim);
        color: var(--blue-light);
    }
    .sb-btn.active::before {
        content: '';
        position: absolute;
        left: 0; top: 50%;
        transform: translateY(-50%);
        width: 3px; height: 20px;
        background: var(--blue);
        border-radius: 0 3px 3px 0;
    }

    /* ── Divider ── */
    .sb-divider {
        width: calc(100% - 24px);
        height: 1px;
        background: var(--border);
        margin: 10px 12px;
        flex-shrink: 0;
    }

    /* ── Avatar at bottom ── */
    .sb-avatar {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        display: grid; place-items: center;
        font-size: .8rem; font-weight: 700;
        color: #fff;
        cursor: pointer;
        margin-top: auto;
        margin-left: 18px;
        flex-shrink: 0;
        transition: box-shadow .15s;
        position: relative;
    }
    .sb-avatar:hover { box-shadow: 0 0 0 3px var(--blue-glow); }
    .sb-avatar-name {
        position: absolute;
        left: 48px;
        white-space: nowrap;
        font-size: .8rem; font-weight: 600;
        color: var(--text-1);
        opacity: 0;
        transform: translateX(-6px);
        transition: opacity .18s ease .04s, transform .18s ease .04s;
        pointer-events: none;
    }
    .sidebar:hover .sb-avatar-name {
        opacity: 1;
        transform: translateX(0);
    }

    /* ─── MAIN ───────────────────────────────────────────────────────── */
    /* ─── MAIN WRAPPER ───────────────────────────────────────────────── */
    .main {
        margin-left: 72px;
        flex: 1;
        display: flex;
        flex-direction: column;
        position: relative;
        z-index: 1;
        transition: margin-left .28s cubic-bezier(.4,0,.2,1);
    }
    .canvas { padding: 24px 20px 40px; display: flex; flex-direction: column; gap: 20px; }

    /* ─── TOAST ──────────────────────────────────────────────────────── */
    .toast {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; border-radius: var(--r-md);
        font-size: .8rem; font-weight: 500;
        animation: fadeDown .25s ease;
    }
    .toast.success { background: var(--income-bg); color: var(--income-clr); border: 1px solid rgba(52,211,153,.25); }
    .toast.error   { background: var(--expense-bg); color: var(--expense-clr); border: 1px solid rgba(248,113,113,.25); }
    .toast svg { width: 15px; height: 15px; flex-shrink: 0; }
    @keyframes fadeDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

    /* ─── PAGE HEADER ────────────────────────────────────────────────── */
    .page-top {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
        flex-wrap: wrap;
    }
    .page-heading h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -.04em; }
    .page-heading p  { font-size: .82rem; color: var(--text-2); margin-top: 4px; }

    /* ─── SUMMARY STRIP ──────────────────────────────────────────────── */
    .summary-strip {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
    }
    .strip-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-md);
        padding: 16px 18px;
        display: flex; align-items: center; gap: 14px;
    }
    .strip-icon {
        width: 36px; height: 36px; border-radius: var(--r-sm);
        display: grid; place-items: center; flex-shrink: 0;
    }
    .strip-icon svg { width: 16px; height: 16px; }
    .strip-icon.inc { background: var(--income-bg); color: var(--income-clr); }
    .strip-icon.exp { background: var(--expense-bg); color: var(--expense-clr); }
    .strip-icon.bal { background: var(--blue-dim); color: var(--blue-light); }
    .strip-label { font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); }
    .strip-val   { font-size: 1.15rem; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; margin-top: 2px; }
    .strip-val.inc { color: var(--income-clr); }
    .strip-val.exp { color: var(--expense-clr); }

    /* ─── TOOLBAR ────────────────────────────────────────────────────── */
    .toolbar {
        display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .search-wrap {
        display: flex; align-items: center; gap: 8px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 40px;
        padding: 8px 16px;
        flex: 1; min-width: 200px; max-width: 340px;
        transition: border-color .15s;
    }
    .search-wrap:focus-within { border-color: var(--border-h); }
    .search-wrap svg { width: 14px; height: 14px; color: var(--text-3); flex-shrink: 0; }
    .search-wrap input {
        background: none; border: none; outline: none;
        font-family: var(--font); font-size: .82rem; color: var(--text-1); width: 100%;
    }
    .search-wrap input::placeholder { color: var(--text-3); }
    .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
    .pill {
        padding: 6px 14px; border-radius: 40px;
        font-size: .75rem; font-weight: 600;
        cursor: pointer; transition: background .15s, color .15s;
        border: 1px solid var(--border);
        background: var(--surface); color: var(--text-2);
    }
    .pill.active { background: var(--blue); color: #fff; border-color: var(--blue); }
    .pill:hover:not(.active) { background: var(--surface-h); color: var(--text-1); }
    .add-btn {
        display: flex; align-items: center; gap: 7px;
        padding: 8px 18px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border: none; border-radius: 40px;
        color: #fff; font-family: var(--font); font-size: .8rem; font-weight: 700;
        cursor: pointer; transition: opacity .15s, box-shadow .15s;
        white-space: nowrap; margin-left: auto;
    }
    .add-btn:hover { opacity: .88; box-shadow: 0 4px 16px var(--blue-glow); }
    .add-btn svg { width: 14px; height: 14px; }

    /* ─── TABLE PANEL ────────────────────────────────────────────────── */
    .table-panel {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        backdrop-filter: blur(12px);
        overflow: hidden;
    }
    .tx-count-bar {
        padding: 14px 20px;
        border-bottom: 1px solid var(--border);
        display: flex; align-items: center; justify-content: space-between;
    }
    .tx-count-bar span { font-size: .78rem; color: var(--text-2); }
    .tx-count-bar strong { color: var(--text-1); }

    /* Table */
    .tx-table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    thead th {
        padding: 10px 16px;
        text-align: left;
        font-size: .67rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--text-3);
        background: rgba(255,255,255,.03);
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }
    thead th:last-child { text-align: right; }
    tbody tr { transition: background .12s; }
    tbody tr:hover { background: var(--surface-h); }
    tbody tr.hidden-row { display: none; }
    tbody td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        color: var(--text-1); vertical-align: middle;
    }
    tbody tr:last-child td { border-bottom: none; }
    tbody td:last-child { text-align: right; }

    .cat-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 9px; border-radius: 20px;
        font-size: .72rem; font-weight: 600;
    }
    .cat-badge.income  { background: var(--income-bg); color: var(--income-clr); }
    .cat-badge.expense { background: var(--expense-bg); color: var(--expense-clr); }
    .cat-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

    .tx-amount { font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
    .tx-amount.income  { color: var(--income-clr); }
    .tx-amount.expense { color: var(--expense-clr); }

    .tx-desc { color: var(--text-2); font-size: .8rem; }
    .tx-date-cell { color: var(--text-2); white-space: nowrap; }

    /* Action buttons in table */
    .action-wrap { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
    .icon-btn {
        width: 28px; height: 28px; border-radius: var(--r-sm);
        background: transparent; border: 1px solid transparent;
        display: grid; place-items: center; cursor: pointer;
        color: var(--text-3); transition: background .15s, color .15s, border-color .15s;
        padding: 0;
    }
    .icon-btn svg { width: 13px; height: 13px; }
    .icon-btn.edit:hover  { background: var(--blue-dim); border-color: rgba(59,130,246,.3); color: var(--blue-light); }
    .icon-btn.del:hover   { background: var(--expense-bg); border-color: rgba(248,113,113,.25); color: var(--expense-clr); }

    .empty-state {
        padding: 60px 20px; text-align: center; color: var(--text-3);
    }
    .empty-state svg { width: 40px; height: 40px; margin: 0 auto 14px; display: block; opacity: .3; }
    .empty-state p { font-size: .85rem; }

    .no-results { display: none; padding: 40px 20px; text-align: center; color: var(--text-3); font-size: .82rem; }
    .no-results.visible { display: block; }

    /* ─── MODAL OVERLAY ──────────────────────────────────────────────── */
    .modal-overlay {
        position: fixed; inset: 0; z-index: 500;
        background: rgba(7,12,24,.7);
        backdrop-filter: blur(6px);
        display: none; place-items: center;
    }
    .modal-overlay.open { display: grid; }
    .modal {
        background: #0f1829;
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        width: 100%; max-width: 420px;
        padding: 24px;
        box-shadow: 0 24px 80px rgba(0,0,0,.5);
        animation: modalIn .2s ease;
    }
    @keyframes modalIn { from { opacity:0; transform:scale(.96) translateY(8px); } to { opacity:1; transform:none; } }
    .modal-head {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 20px;
    }
    .modal-head h2 { font-size: .95rem; font-weight: 700; }
    .modal-close {
        width: 28px; height: 28px; border-radius: var(--r-sm);
        background: var(--surface); border: 1px solid var(--border);
        display: grid; place-items: center; cursor: pointer;
        color: var(--text-2); transition: background .15s;
    }
    .modal-close:hover { background: var(--surface-h); color: var(--text-1); }
    .modal-close svg { width: 14px; height: 14px; }

    /* Form inside modal */
    .field { margin-bottom: 14px; }
    .field label {
        display: block; font-size: .68rem; font-weight: 600;
        color: var(--text-2); margin-bottom: 6px;
        letter-spacing: .04em; text-transform: uppercase;
    }
    .field label .req { color: var(--blue-light); margin-left: 2px; }
    .field input, .field select {
        width: 100%; padding: 9px 13px;
        background: rgba(255,255,255,.05);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        color: var(--text-1);
        font-family: var(--font); font-size: .84rem;
        outline: none;
        transition: border-color .15s, box-shadow .15s;
        -webkit-appearance: none;
    }
    .field input::placeholder { color: var(--text-3); }
    .field input:focus, .field select:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 3px var(--blue-glow);
    }
    .field select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394A3B8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center;
        padding-right: 32px; cursor: pointer;
        background-color: rgba(255,255,255,.05);
    }
    .field select option { background: #1e293b; color: var(--text-1); }
    .prefix-wrap { position: relative; }
    .prefix-sym { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-2); font-size: .84rem; pointer-events: none; }
    .prefix-wrap input { padding-left: 26px; }
    .modal-actions { display: flex; gap: 10px; margin-top: 20px; }
    .modal-cancel {
        flex: 1; padding: 10px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r-sm); color: var(--text-2);
        font-family: var(--font); font-size: .83rem; font-weight: 600;
        cursor: pointer; transition: background .15s;
    }
    .modal-cancel:hover { background: var(--surface-h); color: var(--text-1); }
    .modal-submit {
        flex: 2; padding: 10px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border: none; border-radius: var(--r-sm);
        color: #fff; font-family: var(--font); font-size: .83rem; font-weight: 700;
        cursor: pointer; transition: opacity .15s, box-shadow .15s;
    }
    .modal-submit:hover { opacity: .88; box-shadow: 0 4px 16px var(--blue-glow); }

    /* ─── RESPONSIVE ─────────────────────────────────────────────────── */
    @media (max-width: 640px) {
        .sidebar { display: none; }
        .main { margin-left: 0; }
        .canvas { padding: 16px; }
        .summary-strip { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

<!-- ═══ ICON SIDEBAR ════════════════════════════════════════════════ -->
<aside class="sidebar">
    <!-- Sentimo Logo -->
    <div class="sb-logo-row">
        <div class="sb-logo">
            <div class="sb-logo-inner">
                <span class="sb-logo-text">Se</span>
            </div>
        </div>
        <span class="sb-brand">Sentimo</span>
    </div>
    <nav class="sb-nav">
        <a href="dashboard.php" class="sb-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span class="sb-btn-label">Dashboard</span>
        </a>
        <a href="transactions.php" class="sb-btn active">
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
        <a href="settings.php" class="sb-btn">
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

    <?php
    $toast_type = '';
    $toast_msg  = '';
    if ($message === 'add_success')    { $toast_type = 'success'; $toast_msg = 'Transaction added successfully.'; }
    elseif ($message === 'edit_success')   { $toast_type = 'success'; $toast_msg = 'Transaction updated successfully.'; }
    elseif ($message === 'delete_success') { $toast_type = 'success'; $toast_msg = 'Transaction deleted.'; }
    elseif (str_starts_with($message, 'error:')) { $toast_type = 'error'; $toast_msg = substr($message, 6); }
    ?>
    <?php if ($toast_type): ?>
    <div class="toast <?php echo $toast_type; ?>">
        <?php if ($toast_type === 'success'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php endif; ?>
        <?php echo htmlspecialchars($toast_msg); ?>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-top">
        <div class="page-heading">
            <h1>Transactions</h1>
            <p><?php echo date('l, F j, Y'); ?> — <?php echo count($transactions); ?> total entries</p>
        </div>
    </div>

    <!-- Summary strip -->
    <div class="summary-strip">
        <div class="strip-card">
            <div class="strip-icon inc">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div>
                <div class="strip-label">Total Income</div>
                <div class="strip-val inc">₱<?php echo number_format($total_income, 2); ?></div>
            </div>
        </div>
        <div class="strip-card">
            <div class="strip-icon exp">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
            </div>
            <div>
                <div class="strip-label">Total Expenses</div>
                <div class="strip-val exp">₱<?php echo number_format($total_expense, 2); ?></div>
            </div>
        </div>
        <div class="strip-card">
            <div class="strip-icon bal">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div>
                <div class="strip-label">Net Balance</div>
                <div class="strip-val" style="color:<?php echo $current_balance >= 0 ? 'var(--income-clr)' : 'var(--expense-clr)'; ?>">
                    <?php echo $current_balance < 0 ? '−' : ''; ?>₱<?php echo number_format(abs($current_balance), 2); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="txSearch" placeholder="Search description or category…">
        </div>
        <div class="filter-pills">
            <span class="pill active" data-filter="all">All</span>
            <span class="pill" data-filter="income">Income</span>
            <span class="pill" data-filter="expense">Expenses</span>
            <span class="pill" data-filter="month">This Month</span>
        </div>
        <button class="add-btn" onclick="openAddModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Transaction
        </button>
    </div>

    <!-- Table panel -->
    <div class="table-panel">
        <div class="tx-count-bar">
            <span>Showing <strong id="visibleCount"><?php echo count($transactions); ?></strong> of <strong><?php echo count($transactions); ?></strong> transactions</span>
        </div>

        <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <p>No transactions yet. Click "Add Transaction" to get started.</p>
        </div>
        <?php else: ?>
        <div class="tx-table-wrap">
            <table id="txTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr data-type="<?php echo $t['type']; ?>"
                        data-date="<?php echo $t['date']; ?>"
                        data-label="<?php echo htmlspecialchars(strtolower($t['description'] ?: $t['category_name'])); ?>"
                        data-category="<?php echo htmlspecialchars(strtolower($t['category_name'])); ?>">
                        <td class="tx-date-cell"><?php echo date('M d, Y', strtotime($t['date'])); ?></td>
                        <td>
                            <span class="cat-badge <?php echo $t['type']; ?>">
                                <span class="cat-dot"></span>
                                <?php echo htmlspecialchars($t['category_name']); ?>
                            </span>
                        </td>
                        <td class="tx-desc"><?php echo $t['description'] ? htmlspecialchars($t['description']) : '<span style="color:var(--text-3)">—</span>'; ?></td>
                        <td>
                            <span class="tx-amount <?php echo $t['type']; ?>">
                                <?php echo $t['type'] === 'income' ? '+' : '−'; ?> ₱<?php echo number_format($t['amount'], 2); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-wrap">
                                <!-- Edit -->
                                <button class="icon-btn edit" title="Edit"
                                    onclick="openEditModal(
                                        <?php echo (int)$t['id']; ?>,
                                        <?php echo (int)$t['category_id']; ?>,
                                        '<?php echo addslashes(number_format($t['amount'], 2)); ?>',
                                        '<?php echo addslashes($t['description']); ?>',
                                        '<?php echo $t['date']; ?>'
                                    )">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <!-- Delete -->
                                <form method="POST" action="transactions.php" style="display:contents;">
                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="submit" name="delete_transaction" class="icon-btn del" title="Delete"
                                        onclick="return confirm('Delete this transaction? This cannot be undone.');">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="no-results" id="noResults">No transactions match your search or filter.</div>
        <?php endif; ?>
    </div>

</div><!-- /.canvas -->
</div><!-- /.main -->

<!-- ═══ ADD MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-head">
            <h2>Add Transaction</h2>
            <button class="modal-close" onclick="closeModal('addModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form action="transactions.php" method="POST" autocomplete="off">
            <div class="field">
                <label>Amount <span class="req">*</span></label>
                <div class="prefix-wrap">
                    <span class="prefix-sym">₱</span>
                    <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
            </div>
            <div class="field">
                <label>Category <span class="req">*</span></label>
                <select name="category_id" required>
                    <option value="">— Select —</option>
                    <?php if (!empty($income_cats)): ?>
                    <optgroup label="Income">
                        <?php foreach ($income_cats as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($expense_cats)): ?>
                    <optgroup label="Expense">
                        <?php foreach ($expense_cats as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div class="field">
                <label>Description</label>
                <input type="text" name="description" placeholder="e.g., Jollibee, Monthly allowance…">
            </div>
            <div class="field">
                <label>Date <span class="req">*</span></label>
                <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" name="add_transaction" class="modal-submit">Save Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ EDIT MODAL ═══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-head">
            <h2>Edit Transaction</h2>
            <button class="modal-close" onclick="closeModal('editModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form action="transactions.php" method="POST" autocomplete="off">
            <input type="hidden" name="transaction_id" id="editTxId">
            <div class="field">
                <label>Amount <span class="req">*</span></label>
                <div class="prefix-wrap">
                    <span class="prefix-sym">₱</span>
                    <input type="number" name="amount" id="editAmount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
            </div>
            <div class="field">
                <label>Category <span class="req">*</span></label>
                <select name="category_id" id="editCategory" required>
                    <option value="">— Select —</option>
                    <?php if (!empty($income_cats)): ?>
                    <optgroup label="Income">
                        <?php foreach ($income_cats as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($expense_cats)): ?>
                    <optgroup label="Expense">
                        <?php foreach ($expense_cats as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div class="field">
                <label>Description</label>
                <input type="text" name="description" id="editDesc" placeholder="e.g., Jollibee, Monthly allowance…">
            </div>
            <div class="field">
                <label>Date <span class="req">*</span></label>
                <input type="date" name="date" id="editDate" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" name="edit_transaction" class="modal-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal helpers ────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModal').classList.add('open');
}
function openEditModal(id, categoryId, amount, description, date) {
    document.getElementById('editTxId').value      = id;
    document.getElementById('editAmount').value    = amount;
    document.getElementById('editCategory').value  = categoryId;
    document.getElementById('editDesc').value      = description;
    document.getElementById('editDate').value      = date;
    document.getElementById('editModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});
// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
});

// ── Search + Filter ──────────────────────────────────────────────────
const rows        = document.querySelectorAll('#txTable tbody tr');
const searchInput = document.getElementById('txSearch');
const pills       = document.querySelectorAll('.pill[data-filter]');
const noResults   = document.getElementById('noResults');
const countEl     = document.getElementById('visibleCount');
const currentMonth = new Date().toISOString().slice(0, 7); // "2025-06"

let activeFilter = 'all';
let searchQuery  = '';

function applyFilters() {
    let visible = 0;
    rows.forEach(row => {
        const type     = row.dataset.type;
        const date     = row.dataset.date;
        const label    = row.dataset.label;
        const category = row.dataset.category;

        const matchesFilter =
            activeFilter === 'all' ||
            (activeFilter === 'income'  && type === 'income') ||
            (activeFilter === 'expense' && type === 'expense') ||
            (activeFilter === 'month'   && date.startsWith(currentMonth));

        const matchesSearch =
            searchQuery === '' ||
            label.includes(searchQuery) ||
            category.includes(searchQuery);

        if (matchesFilter && matchesSearch) {
            row.classList.remove('hidden-row');
            visible++;
        } else {
            row.classList.add('hidden-row');
        }
    });

    if (countEl) countEl.textContent = visible;
    if (noResults) noResults.classList.toggle('visible', visible === 0 && rows.length > 0);
}

pills.forEach(pill => {
    pill.addEventListener('click', () => {
        pills.forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        activeFilter = pill.dataset.filter;
        applyFilters();
    });
});

if (searchInput) {
    searchInput.addEventListener('input', () => {
        searchQuery = searchInput.value.toLowerCase().trim();
        applyFilters();
    });
}

// ── Theme: read from localStorage (controlled via Settings) ──────────
(function () {
    const saved = localStorage.getItem('sentimo_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);
})();
</script>
</body>
</html>