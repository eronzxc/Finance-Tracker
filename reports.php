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

// ── TOTALS ────────────────────────────────────────────────────────────
$inc_stmt = $pdo->prepare("SELECT SUM(t.amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'income'");
$inc_stmt->execute([$user_id]);
$total_income = $inc_stmt->fetch()['total'] ?? 0;

$exp_stmt = $pdo->prepare("SELECT SUM(t.amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'expense'");
$exp_stmt->execute([$user_id]);
$total_expense = $exp_stmt->fetch()['total'] ?? 0;

$net_balance = $total_income - $total_expense;
$savings_rate = $total_income > 0 ? round((($total_income - $total_expense) / $total_income) * 100, 1) : 0;

// ── MONTHLY DATA (last 12 months) ─────────────────────────────────────
$monthly_data = [];
for ($i = 11; $i >= 0; $i--) {
    $monthly_data[] = [
        'label'   => date('M Y', strtotime("-$i months")),
        'short'   => date('M', strtotime("-$i months")),
        'year_mo' => date('Y-m', strtotime("-$i months")),
        'income'  => 0,
        'expense' => 0,
    ];
}

$all_tx_stmt = $pdo->prepare("SELECT t.amount, t.date, c.type, c.name AS category_name FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? ORDER BY t.date DESC");
$all_tx_stmt->execute([$user_id]);
$all_transactions = $all_tx_stmt->fetchAll();

foreach ($all_transactions as $t) {
    $ym = substr($t['date'], 0, 7);
    foreach ($monthly_data as &$m) {
        if ($m['year_mo'] === $ym) {
            $t['type'] === 'income' ? $m['income'] += $t['amount'] : $m['expense'] += $t['amount'];
        }
    }
    unset($m);
}

// ── EXPENSE BY CATEGORY ───────────────────────────────────────────────
$cat_stmt = $pdo->prepare("SELECT c.name, SUM(t.amount) AS total FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'expense' GROUP BY c.name ORDER BY total DESC");
$cat_stmt->execute([$user_id]);
$expense_by_cat = $cat_stmt->fetchAll();

// ── TOP 5 BIGGEST EXPENSES ────────────────────────────────────────────
$top_stmt = $pdo->prepare("SELECT t.amount, t.description, t.date, c.name AS category_name FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? AND c.type = 'expense' ORDER BY t.amount DESC LIMIT 5");
$top_stmt->execute([$user_id]);
$top_expenses = $top_stmt->fetchAll();

// ── MONTHLY COMPARISON TABLE (last 6 months) ──────────────────────────
$monthly_table = array_slice($monthly_data, -6);

// Chart colors for category donut
$chart_colors = ['#3B82F6','#818CF8','#34D399','#F59E0B','#F87171','#38BDF8','#A78BFA','#6EE7B7'];

$current_month_str = date('Y-m');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — Sentimo</title>
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
    [data-theme="light"] .search-wrap { background: rgba(255,255,255,.8); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .search-wrap input { color: #0F172A; }
    [data-theme="light"] .search-wrap input::placeholder { color: #94A3B8; }
    [data-theme="light"] .page-greeting { color: #475569; }
    [data-theme="light"] .page-greeting span { color: #0F172A; }
    [data-theme="light"] .notif-dot { border-color: #F0F4FF; }
    [data-theme="light"] .stat-card { background: rgba(255,255,255,.85); border-color: rgba(30,64,175,.1); }
    [data-theme="light"] .stat-label { color: #94A3B8; }
    [data-theme="light"] table thead th { background: rgba(59,130,246,.04); color: #94A3B8; }
    [data-theme="light"] table tbody td { color: #0F172A; border-color: rgba(30,64,175,.08); }
    [data-theme="light"] table tbody tr:hover { background: rgba(59,130,246,.04); }
    [data-theme="light"] .top-rank { background: rgba(59,130,246,.08); color: var(--blue); }
    [data-theme="light"] .month-table td { color: #0F172A; border-color: rgba(30,64,175,.08); }
    [data-theme="light"] .month-table th { background: rgba(59,130,246,.04); color: #94A3B8; }
    [data-theme="light"] .month-table tr:hover td { background: rgba(59,130,246,.04); }
    [data-theme="light"] select option { background: #ffffff !important; color: #0F172A !important; }
    [data-theme="light"] .theme-toggle { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .notif-btn { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .topbar-avatar { background: linear-gradient(135deg, var(--blue), var(--indigo)); }

    /* ─── THEME TOGGLE ───────────────────────────────────────────────── */
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
    .canvas { padding: 18px 20px 40px; display: flex; flex-direction: column; gap: 16px; }

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

    /* ─── GLASS PANEL ────────────────────────────────────────────────── */
    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); backdrop-filter: blur(12px); overflow: hidden; }
    .panel-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--border); }
    .panel-head h2 { font-size: .92rem; font-weight: 700; }
    .panel-head p  { font-size: .72rem; color: var(--text-2); margin-top: 2px; }
    .panel-body { padding: 20px; }

    /* ─── STAT CARDS STRIP ───────────────────────────────────────────── */
    .stats-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
    .stat-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r-md); padding: 16px 18px;
        display: flex; align-items: center; gap: 14px;
    }
    .stat-icon { width: 38px; height: 38px; border-radius: var(--r-sm); display: grid; place-items: center; flex-shrink: 0; }
    .stat-icon svg { width: 17px; height: 17px; }
    .stat-icon.inc { background: var(--income-bg); color: var(--income-clr); }
    .stat-icon.exp { background: var(--expense-bg); color: var(--expense-clr); }
    .stat-icon.bal { background: var(--blue-dim); color: var(--blue-light); }
    .stat-icon.sav { background: rgba(167,139,250,.12); color: var(--violet); }
    .stat-label { font-size: .67rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--text-3); }
    .stat-val   { font-size: 1.2rem; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; margin-top: 3px; }
    .stat-val.inc { color: var(--income-clr); }
    .stat-val.exp { color: var(--expense-clr); }
    .stat-val.sav { color: var(--violet); }

    /* ─── MAIN GRID ──────────────────────────────────────────────────── */
    .reports-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .reports-grid.full { grid-template-columns: 1fr; }

    /* ─── CHART AREAS ────────────────────────────────────────────────── */
    .chart-wrap { position: relative; height: 200px; padding: 0 20px 20px; }
    .chart-wrap canvas { width: 100% !important; height: 100% !important; }

    /* ─── DONUT ROW ──────────────────────────────────────────────────── */
    .donut-row { display: flex; align-items: center; gap: 20px; padding: 0 20px 20px; }
    .donut-chart-container { position: relative; width: 150px; height: 150px; flex-shrink: 0; }
    .donut-chart-container canvas { width: 150px !important; height: 150px !important; }
    .donut-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center; pointer-events: none; }
    .donut-center .dc-val { font-size: 1rem; font-weight: 800; letter-spacing: -.03em; }
    .donut-center .dc-lbl { font-size: .62rem; color: var(--text-2); margin-top: 2px; }
    .cat-legend { flex: 1; display: flex; flex-direction: column; gap: 10px; }
    .legend-row { display: flex; align-items: center; gap: 10px; }
    .legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .legend-name { font-size: .78rem; color: var(--text-2); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .legend-pct  { font-size: .75rem; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; }
    .legend-amt  { font-size: .72rem; color: var(--text-3); font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* ─── TOP EXPENSES TABLE ─────────────────────────────────────────── */
    .top-list { display: flex; flex-direction: column; }
    .top-row {
        display: flex; align-items: center; gap: 14px;
        padding: 12px 20px; border-bottom: 1px solid var(--border);
        transition: background .12s;
    }
    .top-row:last-child { border-bottom: none; }
    .top-row:hover { background: var(--surface-h); }
    .top-rank {
        width: 26px; height: 26px; border-radius: 6px;
        background: var(--blue-dim); color: var(--blue-light);
        display: grid; place-items: center;
        font-size: .72rem; font-weight: 800; flex-shrink: 0;
    }
    .top-rank.r1 { background: rgba(245,158,11,.15); color: #F59E0B; }
    .top-rank.r2 { background: rgba(148,163,184,.1); color: #94A3B8; }
    .top-rank.r3 { background: rgba(180,83,9,.12); color: #D97706; }
    .top-meta { flex: 1; min-width: 0; }
    .top-name { font-size: .82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .top-sub  { font-size: .72rem; color: var(--text-2); margin-top: 2px; }
    .top-amt  { font-size: .88rem; font-weight: 800; color: var(--expense-clr); font-variant-numeric: tabular-nums; flex-shrink: 0; }

    /* ─── MONTHLY TABLE ──────────────────────────────────────────────── */
    .month-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .month-table th {
        padding: 10px 16px; text-align: left;
        font-size: .67rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: .08em; color: var(--text-3);
        background: rgba(255,255,255,.03); border-bottom: 1px solid var(--border);
    }
    .month-table th:not(:first-child) { text-align: right; }
    .month-table td {
        padding: 11px 16px; border-bottom: 1px solid var(--border);
        color: var(--text-1); vertical-align: middle;
    }
    .month-table td:not(:first-child) { text-align: right; font-variant-numeric: tabular-nums; }
    .month-table tr:last-child td { border-bottom: none; }
    .month-table tr:hover td { background: var(--surface-h); }
    .month-label { font-weight: 600; }
    .month-inc { color: var(--income-clr); font-weight: 700; }
    .month-exp { color: var(--expense-clr); font-weight: 700; }
    .month-savings { font-weight: 700; }
    .savings-rate-pill {
        display: inline-block;
        padding: 2px 8px; border-radius: 20px;
        font-size: .68rem; font-weight: 700;
    }
    .savings-rate-pill.pos { background: var(--income-bg); color: var(--income-clr); }
    .savings-rate-pill.neg { background: var(--expense-bg); color: var(--expense-clr); }

    /* ─── EMPTY STATE ────────────────────────────────────────────────── */
    .empty-state { padding: 48px 20px; text-align: center; color: var(--text-3); }
    .empty-state svg { width: 36px; height: 36px; margin: 0 auto 12px; display: block; opacity: .3; }
    .empty-state p { font-size: .82rem; }

    /* ─── RESPONSIVE ─────────────────────────────────────────────────── */
    @media (max-width: 960px) { .reports-grid { grid-template-columns: 1fr; } .stats-strip { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px) { .sidebar { display: none; } .main { margin-left: 0; } .stats-strip { grid-template-columns: 1fr; } .canvas { padding: 16px; } }
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
        <a href="reports.php" class="sb-btn active">
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

    <!-- Page top row -->
    <div class="page-top-row">
        <div class="page-heading">
            <h1>Reports</h1>
            <p><?php echo date('l, F j, Y'); ?> — Financial overview</p>
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

    <!-- Stats Strip -->
    <div class="stats-strip">
        <div class="stat-card">
            <div class="stat-icon inc">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div>
                <div class="stat-label">Total Income</div>
                <div class="stat-val inc">₱<?php echo number_format($total_income, 2); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon exp">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
            </div>
            <div>
                <div class="stat-label">Total Expenses</div>
                <div class="stat-val exp">₱<?php echo number_format($total_expense, 2); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bal">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div>
                <div class="stat-label">Net Balance</div>
                <div class="stat-val" style="color:<?php echo $net_balance >= 0 ? 'var(--income-clr)' : 'var(--expense-clr)'; ?>">
                    <?php echo $net_balance < 0 ? '−' : ''; ?>₱<?php echo number_format(abs($net_balance), 2); ?>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon sav">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0H5m14 0h2M5 21H3"/><path d="M9 7h6M9 11h6M9 15h4"/></svg>
            </div>
            <div>
                <div class="stat-label">Savings Rate</div>
                <div class="stat-val sav"><?php echo $savings_rate; ?>%</div>
            </div>
        </div>
    </div>

    <!-- Row 1: Revenue Flow (full width) -->
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Revenue Flow</h2>
                <p>Income vs expenses over the last 12 months</p>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Row 2: Expense Breakdown + Top Expenses -->
    <div class="reports-grid">

        <!-- Expense Breakdown Donut -->
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Expense Breakdown</h2>
                    <p>Spending by category</p>
                </div>
            </div>
            <?php if (empty($expense_by_cat)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <p>No expense data yet.</p>
            </div>
            <?php else: ?>
            <div class="donut-row">
                <div class="donut-chart-container">
                    <canvas id="donutChart"></canvas>
                    <div class="donut-center">
                        <div class="dc-val" style="color:var(--expense-clr)">₱<?php echo number_format($total_expense, 0); ?></div>
                        <div class="dc-lbl">Total Spent</div>
                    </div>
                </div>
                <div class="cat-legend">
                    <?php
                    $ci = 0;
                    foreach ($expense_by_cat as $cat):
                        $pct = $total_expense > 0 ? round(($cat['total'] / $total_expense) * 100, 1) : 0;
                        $col = $chart_colors[$ci % count($chart_colors)];
                    ?>
                    <div class="legend-row">
                        <span class="legend-dot" style="background:<?php echo $col; ?>"></span>
                        <span class="legend-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                        <span class="legend-pct"><?php echo $pct; ?>%</span>
                        <span class="legend-amt">₱<?php echo number_format($cat['total'], 0); ?></span>
                    </div>
                    <?php $ci++; endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Top 5 Biggest Expenses -->
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Biggest Expenses</h2>
                    <p>Top 5 highest single transactions</p>
                </div>
            </div>
            <?php if (empty($top_expenses)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <p>No expense data yet.</p>
            </div>
            <?php else: ?>
            <div class="top-list">
                <?php foreach ($top_expenses as $idx => $tx):
                    $rank_class = $idx === 0 ? 'r1' : ($idx === 1 ? 'r2' : ($idx === 2 ? 'r3' : ''));
                ?>
                <div class="top-row">
                    <div class="top-rank <?php echo $rank_class; ?>"><?php echo $idx + 1; ?></div>
                    <div class="top-meta">
                        <div class="top-name"><?php echo $tx['description'] ? htmlspecialchars($tx['description']) : htmlspecialchars($tx['category_name']); ?></div>
                        <div class="top-sub"><?php echo htmlspecialchars($tx['category_name']); ?> &middot; <?php echo date('M d, Y', strtotime($tx['date'])); ?></div>
                    </div>
                    <div class="top-amt">− ₱<?php echo number_format($tx['amount'], 2); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Row 3: Monthly Comparison Table (full width) -->
    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Monthly Summary</h2>
                <p>Last 6 months — income, expenses, and savings</p>
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table class="month-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Income</th>
                        <th>Expenses</th>
                        <th>Net Savings</th>
                        <th>Savings Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly_table as $m):
                        $savings = $m['income'] - $m['expense'];
                        $rate    = $m['income'] > 0 ? round(($savings / $m['income']) * 100, 1) : 0;
                        $no_data = $m['income'] == 0 && $m['expense'] == 0;
                    ?>
                    <tr>
                        <td class="month-label"><?php echo $m['label']; ?></td>
                        <td class="month-inc"><?php echo $no_data ? '—' : '₱' . number_format($m['income'], 2); ?></td>
                        <td class="month-exp"><?php echo $no_data ? '—' : '₱' . number_format($m['expense'], 2); ?></td>
                        <td class="month-savings" style="color:<?php echo $savings >= 0 ? 'var(--income-clr)' : 'var(--expense-clr)'; ?>">
                            <?php echo $no_data ? '—' : ($savings < 0 ? '−' : '') . '₱' . number_format(abs($savings), 2); ?>
                        </td>
                        <td>
                            <?php if ($no_data): ?>
                            <span style="color:var(--text-3)">—</span>
                            <?php else: ?>
                            <span class="savings-rate-pill <?php echo $rate >= 0 ? 'pos' : 'neg'; ?>">
                                <?php echo $rate; ?>%
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.canvas -->
</div><!-- /.main -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Revenue Flow Chart (12 months) ───────────────────────────────────
const monthLabels = <?php echo json_encode(array_column($monthly_data, 'short')); ?>;
const incomeData  = <?php echo json_encode(array_map(fn($m) => round($m['income'], 2), $monthly_data)); ?>;
const expenseData = <?php echo json_encode(array_map(fn($m) => round($m['expense'], 2), $monthly_data)); ?>;

const revCtx  = document.getElementById('revenueChart').getContext('2d');
const incGrad = revCtx.createLinearGradient(0, 0, 0, 200);
incGrad.addColorStop(0, 'rgba(99,102,241,0.9)');
incGrad.addColorStop(1, 'rgba(59,130,246,0.5)');

new Chart(revCtx, {
    type: 'bar',
    data: {
        labels: monthLabels,
        datasets: [
            { label: 'Income',  data: incomeData,  backgroundColor: incGrad, borderRadius: 6, borderSkipped: false, barPercentage: 0.42 },
            { label: 'Expense', data: expenseData, backgroundColor: 'rgba(248,113,113,0.4)', borderRadius: 6, borderSkipped: false, barPercentage: 0.42 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(13,20,38,.95)', borderColor: 'rgba(59,130,246,.3)', borderWidth: 1,
                titleColor: '#94A3B8', bodyColor: '#F1F5F9', padding: 10,
                callbacks: { label: ctx => ` ₱${ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2})}` }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#475569', font: { family: 'Inter', size: 11 } }, border: { display: false } },
            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#475569', font: { family: 'Inter', size: 11 }, callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v }, border: { display: false } }
        }
    }
});

// ── Donut Chart ───────────────────────────────────────────────────────
<?php if (!empty($expense_by_cat)): ?>
const donutCtx    = document.getElementById('donutChart').getContext('2d');
const donutLabels = <?php echo json_encode(array_column($expense_by_cat, 'name')); ?>;
const donutVals   = <?php echo json_encode(array_map(fn($c) => round($c['total'], 2), $expense_by_cat)); ?>;
const donutColors = <?php echo json_encode(array_slice($chart_colors, 0, count($expense_by_cat))); ?>;

new Chart(donutCtx, {
    type: 'doughnut',
    data: {
        labels: donutLabels,
        datasets: [{ data: donutVals, backgroundColor: donutColors, borderWidth: 0, hoverOffset: 4 }]
    },
    options: {
        responsive: false, cutout: '70%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(13,20,38,.95)', borderColor: 'rgba(59,130,246,.3)', borderWidth: 1,
                titleColor: '#94A3B8', bodyColor: '#F1F5F9', padding: 10,
                callbacks: { label: ctx => ` ₱${ctx.parsed.toLocaleString('en-PH', {minimumFractionDigits:2})}` }
            }
        }
    }
});
<?php endif; ?>

// ── Dark / Light Mode Toggle ──────────────────────────────────────────
(function () {
    const root    = document.documentElement;
    const btn     = document.getElementById('themeToggle');
    const STORAGE = 'sentimo_theme';
    const saved   = localStorage.getItem(STORAGE) || 'dark';
    root.setAttribute('data-theme', saved);
    btn.addEventListener('click', function () {
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        localStorage.setItem(STORAGE, next);
    });
})();
</script>
</body>
</html>
