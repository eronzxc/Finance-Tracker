<?php
session_start();
require 'db.php';

// SECURITY CHECK
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];

// ── Sentimo: pull display_name from session (set at login), fallback to username ──
$display_name = $_SESSION['display_name'] ?? $username;

// ── PRG: read + clear flash message from session ──
$message = '';
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// 1. CATEGORIES FOR DROPDOWN
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY type, name");
$categories      = $categories_stmt->fetchAll();

// 2. DELETE TRANSACTION (POST → Redirect → Get)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_transaction'])) {
    $del_id = (int) $_POST['transaction_id'];
    if ($del_id > 0) {
        try {
            $del_stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
            $del_stmt->execute([$del_id, $user_id]);
            $_SESSION['success_message'] = 'delete_success';
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:Could not delete transaction.';
        }
    }
    header("Location: dashboard.php");
    exit;
}

// 3. ADD TRANSACTION (POST → Redirect → Get)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $amount      = $_POST['amount'];
    $category_id = $_POST['category_id'];
    $description = trim($_POST['description']);
    $date        = $_POST['date'];

    if (!empty($amount) && !empty($category_id) && !empty($date)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, category_id, amount, description, date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $category_id, $amount, $description, $date]);
            $_SESSION['success_message'] = 'success';
        } catch (PDOException $e) {
            $_SESSION['success_message'] = 'error:' . $e->getMessage();
        }
    } else {
        $_SESSION['success_message'] = 'error:Please fill in all required fields.';
    }
    header("Location: dashboard.php");
    exit;
}

// 4. TOTALS FOR SUMMARY CARDS
$inc_stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'income'");
$inc_stmt->execute([$user_id]);
$total_income = $inc_stmt->fetch()['total'] ?? 0;

$exp_stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'expense'");
$exp_stmt->execute([$user_id]);
$total_expense = $exp_stmt->fetch()['total'] ?? 0;

$current_balance = $total_income - $total_expense;

// 5. ALL TRANSACTIONS
$trans_stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.type FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? ORDER BY t.date DESC, t.id DESC");
$trans_stmt->execute([$user_id]);
$transactions = $trans_stmt->fetchAll();

// Helpers
$income_cats  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expense_cats = array_filter($categories, fn($c) => $c['type'] === 'expense');

// Monthly totals for Revenue Flow bar chart (last 6 months)
$monthly_data = [];
for ($i = 5; $i >= 0; $i--) {
    $monthly_data[] = [
        'label'   => date('M', strtotime("-$i months")),
        'year_mo' => date('Y-m', strtotime("-$i months")),
        'income'  => 0,
        'expense' => 0,
    ];
}
foreach ($transactions as $t) {
    $ym = substr($t['date'], 0, 7);
    foreach ($monthly_data as &$m) {
        if ($m['year_mo'] === $ym) {
            $t['type'] === 'income' ? $m['income'] += $t['amount'] : $m['expense'] += $t['amount'];
        }
    }
    unset($m);
}

// Expense breakdown by category (donut chart)
$cat_totals = [];
foreach ($transactions as $t) {
    if ($t['type'] === 'expense') {
        $name = $t['category_name'];
        $cat_totals[$name] = ($cat_totals[$name] ?? 0) + $t['amount'];
    }
}
arsort($cat_totals);
$top_cats     = array_slice($cat_totals, 0, 4, true);
$donut_colors = ['#3B82F6','#818CF8','#38BDF8','#6EE7B7'];

// Current month string for JS "This Month" filter
$current_month_str = date('Y-m'); // e.g. "2025-06"
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentimo — Dashboard</title>
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

        --r-sm: 8px;
        --r-md: 14px;
        --r-lg: 20px;
        --r-xl: 28px;

        --font: 'Inter', system-ui, sans-serif;
    }

    /* ─── RESET ──────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 14px; }
    body {
        font-family: var(--font);
        background: var(--bg);
        color: var(--text-1);
        min-height: 100vh;
        display: flex;
        overflow-x: hidden;
    }
    body::before {
        content: '';
        position: fixed;
        top: -180px; left: 30px;
        width: 800px; height: 800px;
        background: radial-gradient(circle, rgba(59,130,246,.22) 0%, rgba(99,102,241,.10) 40%, transparent 70%);
        pointer-events: none;
        z-index: 0;
    }
    body::after {
        content: '';
        position: fixed;
        bottom: -80px; right: -60px;
        width: 680px; height: 680px;
        background: radial-gradient(circle, rgba(56,189,248,.15) 0%, rgba(129,140,248,.10) 40%, transparent 70%);
        pointer-events: none;
        z-index: 0;
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

    /* ─── PAGE TOP ROW ───────────────────────────────────────────────── */
    .search-wrap {
        display: flex; align-items: center; gap: 8px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 40px;
        padding: 8px 16px;
        min-width: 240px;
        transition: border-color .15s;
    }
    .search-wrap:focus-within { border-color: var(--border-h); }
    .search-wrap svg { width: 14px; height: 14px; color: var(--text-3); flex-shrink: 0; }
    .search-wrap input {
        background: none; border: none; outline: none;
        font-family: var(--font); font-size: .82rem;
        color: var(--text-1); width: 100%;
    }
    .search-wrap input::placeholder { color: var(--text-3); }
    .page-top-row {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    }
    .page-top-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .page-greeting { font-size: .85rem; font-weight: 600; color: var(--text-2); white-space: nowrap; }
    .page-greeting span { color: var(--text-1); }
    .notif-btn {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: var(--surface);
        border: 1px solid var(--border);
        display: grid; place-items: center;
        cursor: pointer; position: relative;
        transition: background .15s;
    }
    .notif-btn:hover { background: var(--surface-h); }
    .notif-btn svg { width: 16px; height: 16px; color: var(--text-2); }
    .notif-dot {
        position: absolute; top: 6px; right: 6px;
        width: 7px; height: 7px;
        background: var(--blue);
        border-radius: 50%;
        border: 1.5px solid var(--bg);
    }
    .topbar-avatar {
        width: 36px; height: 36px; border-radius: 50%;
        background: linear-gradient(135deg, var(--blue), var(--indigo));
        display: grid; place-items: center;
        font-size: .78rem; font-weight: 700; color: #fff;
        cursor: pointer;
    }

    /* ─── CONTENT CANVAS ─────────────────────────────────────────────── */
    .canvas { padding: 18px 20px 20px; display: flex; flex-direction: column; gap: 14px; }

    /* ─── TOAST ──────────────────────────────────────────────────────── */
    .toast {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; border-radius: var(--r-md);
        font-size: .8rem; font-weight: 500;
        animation: fadeDown .25s ease;
    }
    .toast.success { background: var(--income-bg); color: var(--income-clr); border: 1px solid rgba(52,211,153,.25); }
    .toast.delete  { background: rgba(248,113,113,.10); color: #F87171; border: 1px solid rgba(248,113,113,.25); }
    .toast.error   { background: var(--expense-bg); color: var(--expense-clr); border: 1px solid rgba(248,113,113,.25); }
    .toast svg     { width: 15px; height: 15px; flex-shrink: 0; }
    @keyframes fadeDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

    /* ─── PAGE TITLE ROW ─────────────────────────────────────────────── */
    .page-heading h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -.04em; }
    .page-heading p  { font-size: .82rem; color: var(--text-2); margin-top: 4px; }

    /* ─── FILTER PILLS ───────────────────────────────────────────────── */
    .filter-pills { display: flex; gap: 8px; flex-wrap: wrap; }
    .pill {
        padding: 7px 18px;
        border-radius: 40px;
        font-size: .78rem; font-weight: 600;
        cursor: pointer; transition: background .15s, color .15s;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-2);
    }
    .pill.active { background: var(--blue); color: #fff; border-color: var(--blue); }
    .pill:hover:not(.active) { background: var(--surface-h); color: var(--text-1); }

    /* ─── MAIN GRID ──────────────────────────────────────────────────── */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 16px;
        align-items: start;
    }
    .left-col { display: flex; flex-direction: column; gap: 14px; }
    .right-col {
        display: flex; flex-direction: column; gap: 12px;
        position: sticky;
        top: 24px;
        max-height: calc(100vh - 48px);
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
        padding-right: 2px;
    }
    .right-col::-webkit-scrollbar { display: none; }

    /* ─── GLASS PANEL ────────────────────────────────────────────────── */
    .panel {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        backdrop-filter: blur(12px);
        overflow: hidden;
    }
    .panel-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 18px 12px;
    }
    .panel-head h2 { font-size: .92rem; font-weight: 700; }
    .view-all {
        font-size: .72rem; font-weight: 600; color: var(--blue-light);
        display: flex; align-items: center; gap: 3px;
        cursor: pointer; text-decoration: none;
    }
    .view-all svg { width: 12px; height: 12px; }

    /* ─── TABBED PANEL (Transactions / New Entry merge) ─────────────── */
    .panel-tabs {
        display: flex; gap: 6px;
        padding: 12px 16px 10px;
    }
    .tab-btn {
        flex: 1;
        padding: 8px 0;
        text-align: center;
        font-size: .78rem; font-weight: 700;
        color: var(--text-2);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
    }
    .tab-btn.active { background: var(--blue); color: #fff; border-color: var(--blue); }
    .tab-btn:hover:not(.active) { background: var(--surface-h); color: var(--text-1); }
    .tab-content { display: none; }
    .tab-content.active {
        display: block;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,.15) transparent;
    }
    .tab-content.active::-webkit-scrollbar { width: 5px; }
    .tab-content.active::-webkit-scrollbar-track { background: transparent; }
    .tab-content.active::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 10px; }
    .tab-content.active::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.25); }

    /* ─── REVENUE FLOW CHART ─────────────────────────────────────────── */
    .chart-area { padding: 0 20px 20px; position: relative; }
    .chart-canvas-wrap { position: relative; height: 118px; }
    canvas#revenueChart { width: 100% !important; height: 100% !important; }

    /* ─── BOTTOM ROW ─────────────────────────────────────────────────── */
    .bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

    .donut-wrap {
        display: flex; align-items: center; gap: 16px;
        padding: 12px 16px 16px;
    }
    .donut-chart-wrap { position: relative; width: 120px; height: 120px; flex-shrink: 0; }
    canvas#donutChart { width: 120px !important; height: 120px !important; }
    .donut-center {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%,-50%);
        text-align: center; pointer-events: none;
    }
    .donut-center .amt  { font-size: 1rem; font-weight: 800; letter-spacing: -.03em; }
    .donut-center .lbl  { font-size: .6rem; color: var(--text-2); margin-top: 1px; }
    .donut-legend { flex: 1; display: flex; flex-direction: column; gap: 8px; }
    .legend-item { display: flex; align-items: center; gap: 8px; }
    .legend-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .legend-name { font-size: .75rem; color: var(--text-2); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .legend-val  { font-size: .75rem; font-weight: 700; font-variant-numeric: tabular-nums; }

    .ie-card { padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; }
    .ie-label  { font-size: .82rem; font-weight: 700; color: var(--text-2); }
    .ie-amount { font-size: 1.25rem; font-weight: 800; letter-spacing: -.04em; font-variant-numeric: tabular-nums; }
    .ie-sub    { font-size: .72rem; color: var(--text-2); }
    .ie-badge  {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 20px;
        font-size: .7rem; font-weight: 700;
        align-self: flex-start; margin-top: 4px;
    }
    .ie-badge.inc { background: var(--income-bg); color: var(--income-clr); }
    .ie-badge.exp { background: var(--expense-bg); color: var(--expense-clr); }
    .ie-divider { height: 1px; background: var(--border); }

    /* ─── BALANCE CARD ───────────────────────────────────────────────── */
    .balance-card {
        background: linear-gradient(135deg, #1a3a6b 0%, #0f2150 40%, #0d1a3e 100%);
        border: 1px solid rgba(59,130,246,.2);
        border-radius: var(--r-lg);
        padding: 14px 18px;
        position: relative;
        overflow: hidden;
    }
    .balance-card::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 180px; height: 180px;
        background: radial-gradient(circle, rgba(59,130,246,.25) 0%, transparent 70%);
        pointer-events: none;
    }
    .balance-card::after {
        content: '';
        position: absolute;
        bottom: -30px; left: 20px;
        width: 140px; height: 140px;
        background: radial-gradient(circle, rgba(129,140,248,.15) 0%, transparent 70%);
        pointer-events: none;
    }
    .bc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; position: relative; z-index: 1; }
    .bc-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.5); }
    .bc-plus-btn {
        width: 32px; height: 32px; border-radius: 50%;
        background: rgba(255,255,255,.15);
        border: 1px solid rgba(255,255,255,.2);
        display: grid; place-items: center; cursor: pointer;
        transition: background .15s;
    }
    .bc-plus-btn:hover { background: rgba(255,255,255,.25); }
    .bc-plus-btn svg { width: 14px; height: 14px; stroke: #fff; stroke-width: 2.5; }
    .bc-amount {
        font-size: 1.6rem; font-weight: 800; letter-spacing: -.05em;
        font-variant-numeric: tabular-nums;
        position: relative; z-index: 1;
        line-height: 1;
    }
    .bc-amount.positive { color: #fff; }
    .bc-amount.negative { color: var(--expense-clr); }
    .bc-sub { font-size: .72rem; color: rgba(255,255,255,.4); margin-top: 6px; position: relative; z-index: 1; }
    .bc-chips { display: flex; gap: 6px; margin-top: 8px; position: relative; z-index: 1; }
    .bc-chip {
        flex: 1; padding: 8px 10px;
        background: rgba(255,255,255,.07);
        border: 1px solid rgba(255,255,255,.1);
        border-radius: var(--r-sm);
        text-align: center;
    }
    .bc-chip .c-lbl { font-size: .6rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .06em; }
    .bc-chip .c-val { font-size: .82rem; font-weight: 700; margin-top: 3px; font-variant-numeric: tabular-nums; }
    .bc-chip.inc .c-val { color: var(--income-clr); }
    .bc-chip.exp .c-val { color: var(--expense-clr); }

    /* ─── TRANSACTIONS PANEL ─────────────────────────────────────────── */
    .tx-list { display: flex; flex-direction: column; }
    .tx-row {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 16px;
        border-bottom: 1px solid var(--border);
        transition: background .12s;
    }
    .tx-row:last-child { border-bottom: none; }
    .tx-row:hover { background: var(--surface-h); }
    .tx-icon {
        width: 36px; height: 36px; border-radius: 50%;
        display: grid; place-items: center;
        font-size: .75rem; font-weight: 700;
        flex-shrink: 0;
        background: var(--blue-dim);
        color: var(--blue-light);
    }
    .tx-icon.expense { background: var(--expense-bg); color: var(--expense-clr); }
    .tx-icon.income  { background: var(--income-bg);  color: var(--income-clr); }
    .tx-meta { flex: 1; min-width: 0; }
    .tx-name { font-size: .82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tx-date-small { font-size: .7rem; color: var(--text-2); margin-top: 2px; }
    .tx-amt { font-size: .85rem; font-weight: 700; font-variant-numeric: tabular-nums; flex-shrink: 0; }
    .tx-amt.income  { color: var(--income-clr); }
    .tx-amt.expense { color: var(--expense-clr); }
    .tx-empty { padding: 32px 20px; text-align: center; color: var(--text-3); font-size: .82rem; }

    /* Delete button inside tx-row */
    .tx-delete-btn {
        width: 28px; height: 28px;
        border-radius: var(--r-sm);
        background: transparent;
        border: 1px solid transparent;
        display: grid; place-items: center;
        cursor: pointer;
        color: var(--text-3);
        transition: background .15s, color .15s, border-color .15s;
        flex-shrink: 0;
        padding: 0;
    }
    .tx-delete-btn svg { width: 14px; height: 14px; }
    .tx-row:hover .tx-delete-btn {
        background: var(--expense-bg);
        border-color: rgba(248,113,113,.2);
        color: var(--expense-clr);
    }
    .tx-delete-btn:hover {
        background: rgba(248,113,113,.2) !important;
        border-color: rgba(248,113,113,.4) !important;
    }

    /* No-results state for filtered list */
    .tx-no-results {
        display: none;
        padding: 28px 20px; text-align: center;
        color: var(--text-3); font-size: .82rem;
    }
    .tx-no-results.visible { display: block; }

    /* ─── ADD ENTRY PANEL ────────────────────────────────────────────── */
    .add-panel { padding: 14px 16px 16px; }
    .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .field { margin-bottom: 8px; }
    .field label {
        display: block; font-size: .68rem; font-weight: 600;
        color: var(--text-2); margin-bottom: 4px; letter-spacing: .04em;
        text-transform: uppercase;
    }
    .field label .req { color: var(--blue-light); margin-left: 2px; }
    .field input, .field select {
        width: 100%; padding: 7px 10px;
        background: rgba(255,255,255,.05);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        color: var(--text-1);
        font-family: var(--font); font-size: .83rem;
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
        background-repeat: no-repeat;
        background-position: right 12px center;
        padding-right: 32px;
        cursor: pointer;
        background-color: rgba(255,255,255,.05);
    }
    .field select option { background: #1e293b; color: var(--text-1); }
    .prefix-wrap { position: relative; }
    .prefix-sym { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-2); font-size: .82rem; pointer-events: none; }
    .prefix-wrap input { padding-left: 26px; }
    .save-btn {
        width: 100%; padding: 10px;
        background: linear-gradient(135deg, var(--blue) 0%, var(--indigo) 100%);
        border: none; border-radius: var(--r-sm);
        color: #fff; font-family: var(--font); font-size: .84rem; font-weight: 700;
        cursor: pointer; letter-spacing: .01em;
        display: flex; align-items: center; justify-content: center; gap: 7px;
        transition: opacity .15s, transform .1s, box-shadow .15s;
        margin-top: 8px;
    }
    .save-btn:hover  { opacity: .9; box-shadow: 0 6px 20px var(--blue-glow); }
    .save-btn:active { transform: scale(.98); }
    .save-btn svg    { width: 14px; height: 14px; }

    /* ─── RESPONSIVE ─────────────────────────────────────────────────── */
    @media (max-width: 1024px) {
        .dashboard-grid { grid-template-columns: 1fr; }
        .right-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    }
    @media (max-width: 640px) {
        .sidebar { display: none; }
        .main    { margin-left: 0; }
        .right-col { grid-template-columns: 1fr; }
        .bottom-row { grid-template-columns: 1fr; }
        .canvas  { padding: 16px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .toast { animation: none; }
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
        <a href="dashboard.php" class="sb-btn active">
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

        <!-- Toast Alerts (PRG session-based) -->
        <?php if ($message === 'success'): ?>
        <div class="toast success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Transaction saved successfully.
        </div>
        <?php elseif ($message === 'delete_success'): ?>
        <div class="toast delete">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            Transaction deleted successfully.
        </div>
        <?php elseif (str_starts_with($message, 'error:')): ?>
        <div class="toast error">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars(substr($message, 6)); ?>
        </div>
        <?php endif; ?>

        <!-- Page heading + search + filters -->
        <div>
            <div class="page-top-row">
                <div class="page-heading">
                    <h1>My Dashboard</h1>
                    <p><?php echo date('l, F j, Y'); ?></p>
                </div>
                <div class="page-top-actions">
                    <div class="search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="txSearchInput" placeholder="Search transactions…">
                    </div>
                    <!-- Sentimo: display_name shown in greeting -->
                    <span class="page-greeting">Hi, <span><?php echo htmlspecialchars($display_name); ?>!</span></span>
                    <div class="notif-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <span class="notif-dot"></span>
                    </div>
                    <div class="topbar-avatar"><?php echo strtoupper(substr($display_name, 0, 1)); ?></div>
                </div>
            </div>
            <div class="filter-pills" style="margin-top:16px;">
                <span class="pill active" data-filter="all">All</span>
                <span class="pill" data-filter="income">Income</span>
                <span class="pill" data-filter="expenses">Expenses</span>
                <span class="pill" data-filter="thismonth">This Month</span>
            </div>
        </div>

        <!-- Main grid -->
        <div class="dashboard-grid">

            <!-- LEFT COLUMN -->
            <div class="left-col">

                <!-- Revenue Flow -->
                <div class="panel">
                    <div class="panel-head">
                        <h2>Revenue Flow</h2>
                        <a href="#" class="view-all">View All <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></a>
                    </div>
                    <div class="chart-area">
                        <div class="chart-canvas-wrap">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Bottom row: Available + Income + Expense -->
                <div class="bottom-row">

                    <!-- Donut / Available -->
                    <div class="panel">
                        <div class="panel-head">
                            <h2>Available</h2>
                            <a href="#" class="view-all">View All <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></a>
                        </div>
                        <div class="donut-wrap">
                            <div class="donut-chart-wrap">
                                <canvas id="donutChart"></canvas>
                                <div class="donut-center">
                                    <div class="amt">₱<?php echo number_format($total_expense, 0); ?></div>
                                    <div class="lbl">Expenses</div>
                                </div>
                            </div>
                            <div class="donut-legend">
                                <?php
                                $di = 0;
                                if (empty($top_cats)): ?>
                                    <span style="font-size:.75rem;color:var(--text-3)">No data yet</span>
                                <?php else:
                                    foreach ($top_cats as $cname => $camt): ?>
                                    <div class="legend-item">
                                        <span class="legend-dot" style="background:<?php echo $donut_colors[$di % count($donut_colors)]; ?>"></span>
                                        <span class="legend-name"><?php echo htmlspecialchars($cname); ?></span>
                                        <span class="legend-val">₱<?php echo number_format($camt, 0); ?></span>
                                    </div>
                                <?php $di++; endforeach; endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Income + Expense stacked -->
                    <div class="panel">
                        <div class="ie-card">
                            <div class="ie-label">Income</div>
                            <div class="ie-amount" style="color:var(--income-clr)">₱<?php echo number_format($total_income, 2); ?></div>
                            <div class="ie-sub">Total recorded earnings</div>
                            <span class="ie-badge inc">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
                                All time
                            </span>
                        </div>
                        <div class="ie-divider"></div>
                        <div class="ie-card">
                            <div class="ie-label">Expense</div>
                            <div class="ie-amount" style="color:var(--expense-clr)">₱<?php echo number_format($total_expense, 2); ?></div>
                            <div class="ie-sub">Total recorded spending</div>
                            <span class="ie-badge exp">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                All time
                            </span>
                        </div>
                    </div>

                </div><!-- /.bottom-row -->
            </div><!-- /.left-col -->

            <!-- RIGHT COLUMN -->
            <div class="right-col">

                <!-- Balance Card -->
                <div class="balance-card">
                    <div class="bc-top">
                        <span class="bc-label">Net Balance</span>
                        <div class="bc-plus-btn" onclick="switchToAddTab()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                    </div>
                    <div class="bc-amount <?php echo $current_balance >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $current_balance < 0 ? '−' : ''; ?>₱<?php echo number_format(abs($current_balance), 2); ?>
                    </div>
                    <div class="bc-sub"><?php echo $current_balance >= 0 ? 'You\'re in the green 🎉' : 'Over budget — review expenses'; ?></div>
                    <div class="bc-chips">
                        <div class="bc-chip inc">
                            <div class="c-lbl">Income</div>
                            <div class="c-val">₱<?php echo number_format($total_income, 0); ?></div>
                        </div>
                        <div class="bc-chip exp">
                            <div class="c-lbl">Spent</div>
                            <div class="c-val">₱<?php echo number_format($total_expense, 0); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Transactions / New Entry — merged tabbed panel -->
                <div class="panel">
                    <div class="panel-tabs">
                        <button type="button" class="tab-btn active" data-tab="recent">Recent</button>
                        <button type="button" class="tab-btn" data-tab="add">Add Entry</button>
                    </div>

                    <!-- Tab: Recent Transactions -->
                    <div class="tab-content active" data-tab-content="recent">
                        <div class="tx-list" id="txList">
                            <?php if (empty($transactions)): ?>
                            <div class="tx-empty">No transactions yet. Add one below!</div>
                            <?php else:
                                $shown = array_slice($transactions, 0, 15);
                                foreach ($shown as $t):
                                    $initials = strtoupper(substr($t['category_name'], 0, 2));
                                    // data-attributes for JS filtering
                                    $row_type    = htmlspecialchars($t['type']);
                                    $row_date    = htmlspecialchars($t['date']); // e.g. 2025-06-10
                                    $row_label   = htmlspecialchars(strtolower($t['description'] ?: $t['category_name']));
                                    $row_cat     = htmlspecialchars(strtolower($t['category_name']));
                            ?>
                            <div class="tx-row"
                                 data-type="<?php echo $row_type; ?>"
                                 data-date="<?php echo $row_date; ?>"
                                 data-label="<?php echo $row_label; ?>"
                                 data-category="<?php echo $row_cat; ?>">
                                <div class="tx-icon <?php echo $t['type']; ?>"><?php echo $initials; ?></div>
                                <div class="tx-meta">
                                    <div class="tx-name"><?php echo $t['description'] ? htmlspecialchars($t['description']) : htmlspecialchars($t['category_name']); ?></div>
                                    <div class="tx-date-small"><?php echo date('M d, Y', strtotime($t['date'])); ?></div>
                                </div>
                                <div class="tx-amt <?php echo $t['type']; ?>">
                                    <?php echo $t['type'] === 'income' ? '+' : '−'; ?> ₱<?php echo number_format($t['amount'], 2); ?>
                                </div>
                                <!-- Delete button -->
                                <form method="POST" action="dashboard.php" style="margin:0;padding:0;display:contents;">
                                    <input type="hidden" name="transaction_id" value="<?php echo (int)$t['id']; ?>">
                                    <button
                                        type="submit"
                                        name="delete_transaction"
                                        class="tx-delete-btn"
                                        title="Delete transaction"
                                        onclick="return confirm('Delete this transaction? This cannot be undone.');">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"/>
                                            <path d="M19 6l-1 14H6L5 6"/>
                                            <path d="M10 11v6"/><path d="M14 11v6"/>
                                            <path d="M9 6V4h6v2"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <!-- No-results state for live filter -->
                        <div class="tx-no-results" id="txNoResults">No matching transactions found.</div>
                    </div>

                    <!-- Tab: Add New Entry -->
                    <div class="tab-content" data-tab-content="add">
                        <div class="add-panel">
                            <form action="dashboard.php" method="POST" autocomplete="off">
                                <div class="field-grid">
                                    <div class="field">
                                        <label>Amount <span class="req">*</span></label>
                                        <div class="prefix-wrap">
                                            <span class="prefix-sym">₱</span>
                                            <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
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
                                </div>
                                <div class="field">
                                    <label>Description</label>
                                    <input type="text" name="description" placeholder="e.g., Jollibee, Allowance…">
                                </div>
                                <div class="field" style="margin-bottom:0;">
                                    <label>Date <span class="req">*</span></label>
                                    <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <button type="submit" name="add_transaction" class="save-btn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Save Entry
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div><!-- /.right-col -->
        </div><!-- /.dashboard-grid -->
    </div><!-- /.canvas -->
</div><!-- /.main -->

<!-- ═══ CHART.JS ══════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Revenue Flow Bar Chart ──────────────────────────────────────────
const monthLabels  = <?php echo json_encode(array_column($monthly_data, 'label')); ?>;
const incomeData   = <?php echo json_encode(array_map(fn($m) => round($m['income'], 2), $monthly_data)); ?>;
const expenseData  = <?php echo json_encode(array_map(fn($m) => round($m['expense'], 2), $monthly_data)); ?>;

const revenueCtx = document.getElementById('revenueChart').getContext('2d');

const incGrad = revenueCtx.createLinearGradient(0, 0, 0, 160);
incGrad.addColorStop(0, 'rgba(99,102,241,0.9)');
incGrad.addColorStop(1, 'rgba(59,130,246,0.5)');

new Chart(revenueCtx, {
    type: 'bar',
    data: {
        labels: monthLabels,
        datasets: [
            {
                label: 'Income',
                data: incomeData,
                backgroundColor: incGrad,
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.45,
            },
            {
                label: 'Expense',
                data: expenseData,
                backgroundColor: 'rgba(248,113,113,0.35)',
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.45,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(13,20,38,0.95)',
                borderColor: 'rgba(59,130,246,0.3)',
                borderWidth: 1,
                titleColor: '#94A3B8',
                bodyColor: '#F1F5F9',
                padding: 10,
                callbacks: {
                    label: ctx => ` ₱${ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2})}`
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: '#475569', font: { family: 'Inter', size: 11 } },
                border: { display: false }
            },
            y: {
                grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false },
                ticks: {
                    color: '#475569',
                    font: { family: 'Inter', size: 11 },
                    callback: v => v >= 1000 ? (v/1000).toFixed(1)+'k' : v
                },
                border: { display: false }
            }
        }
    }
});

// ── Donut Chart ─────────────────────────────────────────────────────
const donutCtx    = document.getElementById('donutChart').getContext('2d');
const donutLabels = <?php echo json_encode(array_keys($top_cats)); ?>;
const donutVals   = <?php echo json_encode(array_values($top_cats)); ?>;
const donutColors = <?php echo json_encode(array_values($donut_colors)); ?>;

new Chart(donutCtx, {
    type: 'doughnut',
    data: {
        labels: donutLabels.length ? donutLabels : ['No data'],
        datasets: [{
            data:            donutVals.length  ? donutVals  : [1],
            backgroundColor: donutVals.length  ? donutColors : ['rgba(255,255,255,0.08)'],
            borderWidth: 0,
            hoverOffset: 4,
        }]
    },
    options: {
        responsive: false,
        cutout: '72%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(13,20,38,0.95)',
                borderColor: 'rgba(59,130,246,0.3)',
                borderWidth: 1,
                titleColor: '#94A3B8',
                bodyColor: '#F1F5F9',
                callbacks: {
                    label: ctx => ` ₱${ctx.parsed.toLocaleString('en-PH', {minimumFractionDigits:2})}`
                }
            }
        }
    }
});

// ══════════════════════════════════════════════════════════════════════
// ── TABBED PANEL (Recent / Add Entry) ────────────────────────────────
// ══════════════════════════════════════════════════════════════════════
(function () {
    const tabBtns   = document.querySelectorAll('.tab-btn[data-tab]');
    const tabPanels = document.querySelectorAll('.tab-content[data-tab-content]');
    const addPanel  = document.querySelector('.tab-content[data-tab-content="add"]');

    // The "Add Entry" form always has the same fields — its natural height
    // never changes. So THAT height becomes the shared, fixed height for
    // both tabs. "Recent" then scrolls internally if it has more
    // transactions than that height allows — no more empty gutter under
    // Save Entry, no more elongated card.
    function measureAddNaturalHeight() {
        if (!addPanel) return null;
        const prevDisplay = addPanel.style.display;
        const prevHeight  = addPanel.style.height;
        const prevMaxH    = addPanel.style.maxHeight;

        addPanel.style.display   = 'block';
        addPanel.style.height    = 'auto';
        addPanel.style.maxHeight = 'none';

        const naturalHeight = addPanel.scrollHeight;

        addPanel.style.display   = prevDisplay;
        addPanel.style.height    = prevHeight;
        addPanel.style.maxHeight = prevMaxH;

        return naturalHeight;
    }

    function syncPanelHeights() {
        const h = measureAddNaturalHeight();
        if (!h) return;
        tabPanels.forEach(function (p) {
            p.style.height    = h + 'px';
            p.style.maxHeight = h + 'px';
            p.style.overflowY = 'auto';
        });
    }

    syncPanelHeights();
    window.addEventListener('resize', syncPanelHeights);

    function activateTab(tabName) {
        tabBtns.forEach(b => b.classList.toggle('active', b.dataset.tab === tabName));
        tabPanels.forEach(function (p) {
            const isActive = p.dataset.tabContent === tabName;
            p.classList.toggle('active', isActive);
            p.style.display = isActive ? 'block' : 'none';
        });
    }

    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateTab(btn.dataset.tab);
        });
    });

    // Exposed globally so the balance card's "+" button can jump straight to Add Entry
    window.switchToAddTab = function () {
        activateTab('add');
        const amountInput = document.getElementById('amount');
        if (amountInput) amountInput.focus();
    };
})();

// ══════════════════════════════════════════════════════════════════════
// ── LIVE SEARCH + FILTER PILLS (Vanilla JS, zero AJAX, zero reload) ──
// ══════════════════════════════════════════════════════════════════════
(function () {
    const txList     = document.getElementById('txList');
    const noResults  = document.getElementById('txNoResults');
    const searchInput = document.getElementById('txSearchInput');
    const pills       = document.querySelectorAll('.pill[data-filter]');

    // Current month prefix from PHP e.g. "2025-06"
    const CURRENT_MONTH = <?php echo json_encode($current_month_str); ?>;

    let activeFilter = 'all';
    let searchQuery  = '';

    function applyFilters() {
        const rows = txList.querySelectorAll('.tx-row');
        let visibleCount = 0;

        rows.forEach(function (row) {
            const type     = row.dataset.type;     // "income" | "expense"
            const date     = row.dataset.date;     // "2025-06-10"
            const label    = row.dataset.label;    // description lowercased
            const category = row.dataset.category; // category name lowercased

            // 1. Filter pill check
            let pillPass = true;
            if (activeFilter === 'income')    pillPass = (type === 'income');
            if (activeFilter === 'expenses')  pillPass = (type === 'expense');
            if (activeFilter === 'thismonth') pillPass = date.startsWith(CURRENT_MONTH);

            // 2. Search query check (matches description OR category)
            let searchPass = true;
            if (searchQuery.length > 0) {
                searchPass = label.includes(searchQuery) || category.includes(searchQuery);
            }

            const visible = pillPass && searchPass;
            row.style.display = visible ? 'flex' : 'none';
            if (visible) visibleCount++;
        });

        // Show/hide the empty state
        noResults.classList.toggle('visible', visibleCount === 0 && rows.length > 0);
    }

    // Filter pills
    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            pills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            activeFilter = pill.dataset.filter;
            applyFilters();
        });
    });

    // Live search
    searchInput.addEventListener('input', function () {
        searchQuery = searchInput.value.trim().toLowerCase();
        applyFilters();
    });
})();
</script>
</body>
</html>