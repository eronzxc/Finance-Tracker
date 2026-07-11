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

// ── ALL TRANSACTIONS (source data for smart notifications) ─────────────
$tx_stmt = $pdo->prepare("SELECT t.id, t.amount, t.description, t.date, c.name AS category_name, c.type FROM transactions t JOIN categories c ON t.category_id = c.id WHERE t.user_id = ? ORDER BY t.date DESC, t.id DESC");
$tx_stmt->execute([$user_id]);
$transactions = $tx_stmt->fetchAll();

// ── MONTHLY BUCKETS (last 12 months, for trend + streak detection) ─────
$monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $monthly[] = [
        'year_mo' => date('Y-m', strtotime("-$i months")),
        'income'  => 0,
        'expense' => 0,
        'cats'    => [],
    ];
}
foreach ($transactions as $t) {
    $ym = substr($t['date'], 0, 7);
    foreach ($monthly as &$m) {
        if ($m['year_mo'] === $ym) {
            if ($t['type'] === 'income') {
                $m['income'] += $t['amount'];
            } else {
                $m['expense'] += $t['amount'];
                $m['cats'][$t['category_name']] = ($m['cats'][$t['category_name']] ?? 0) + $t['amount'];
            }
        }
    }
    unset($m);
}

$current_month_str = date('Y-m');
$this_month = $monthly[count($monthly) - 1];
$prev_month = $monthly[count($monthly) - 2] ?? null;

// ── ICON LIBRARY (feather-style, matches rest of app) ───────────────────
$icons = [
    'calendar'       => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'trending-up'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    'trending-down'  => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>',
    'pie-chart'      => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
    'award'          => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
];

// ── SMART NOTIFICATION GENERATION (derived purely from transactions) ───
$notifications = [];

if ($this_month['income'] == 0 && $this_month['expense'] == 0) {
    // 1. No activity logged this month
    $notifications[] = [
        'id' => 'no-tx-' . $current_month_str, 'type' => 'info', 'icon' => 'calendar',
        'title'   => 'No activity this month',
        'message' => "You haven't logged any transactions for " . date('F Y') . " yet. Add one on the Dashboard to keep your records up to date.",
        'date'    => date('Y-m-d'),
    ];
} else {
    // 2. Spending trend vs last month
    if ($prev_month && $prev_month['expense'] > 0 && $this_month['expense'] > 0) {
        $change_pct = (($this_month['expense'] - $prev_month['expense']) / $prev_month['expense']) * 100;
        if ($change_pct >= 20) {
            $notifications[] = [
                'id' => 'spike-' . $current_month_str, 'type' => 'warning', 'icon' => 'trending-up',
                'title'   => 'Spending is up this month',
                'message' => 'Your expenses are ' . round($change_pct) . '% higher than ' . date('F', strtotime($prev_month['year_mo'] . '-01')) . ' (₱' . number_format($this_month['expense'], 2) . ' vs ₱' . number_format($prev_month['expense'], 2) . ').',
                'date'    => date('Y-m-d'),
            ];
        } elseif ($change_pct <= -20) {
            $notifications[] = [
                'id' => 'drop-' . $current_month_str, 'type' => 'success', 'icon' => 'trending-down',
                'title'   => 'Spending is down this month',
                'message' => 'Nice — your expenses dropped ' . round(abs($change_pct)) . '% compared to ' . date('F', strtotime($prev_month['year_mo'] . '-01')) . '. Keep it up.',
                'date'    => date('Y-m-d'),
            ];
        }
    }

    // 3. Highest spending category this month
    if (!empty($this_month['cats'])) {
        arsort($this_month['cats']);
        $top_cat_name = array_key_first($this_month['cats']);
        $top_cat_amt  = $this_month['cats'][$top_cat_name];
        $share = $this_month['expense'] > 0 ? round(($top_cat_amt / $this_month['expense']) * 100) : 0;
        $notifications[] = [
            'id' => 'topcat-' . $current_month_str, 'type' => 'info', 'icon' => 'pie-chart',
            'title'   => 'Biggest category: ' . $top_cat_name,
            'message' => 'You\'ve spent ₱' . number_format($top_cat_amt, 2) . " on {$top_cat_name} this month — that's {$share}% of your total expenses.",
            'date'    => date('Y-m-d'),
        ];
    }

    // 4. Large single expense this month (30%+ of the month's total)
    $this_month_expenses = array_values(array_filter($transactions, fn($t) => $t['type'] === 'expense' && substr($t['date'], 0, 7) === $current_month_str));
    if (count($this_month_expenses) > 1 && $this_month['expense'] > 0) {
        usort($this_month_expenses, fn($a, $b) => $b['amount'] <=> $a['amount']);
        $biggest = $this_month_expenses[0];
        $share = round(($biggest['amount'] / $this_month['expense']) * 100);
        if ($share >= 30) {
            $desc = trim($biggest['description']) !== '' ? $biggest['description'] : $biggest['category_name'];
            $notifications[] = [
                'id' => 'bigexp-' . $biggest['id'], 'type' => 'warning', 'icon' => 'alert-triangle',
                'title'   => 'Large expense recorded',
                'message' => "{$desc} (₱" . number_format($biggest['amount'], 2) . ") made up {$share}% of this month's expenses.",
                'date'    => $biggest['date'],
            ];
        }
    }
}

// 5. Positive savings streak (consecutive months, most recent first, income > expense)
$streak = 0;
for ($i = count($monthly) - 1; $i >= 0; $i--) {
    $m = $monthly[$i];
    if ($m['income'] > 0 && $m['income'] > $m['expense']) {
        $streak++;
    } else {
        break;
    }
}
if ($streak >= 2) {
    $notifications[] = [
        'id' => 'streak-' . $current_month_str . '-' . $streak, 'type' => 'success', 'icon' => 'award',
        'title'   => $streak . '-month positive savings streak',
        'message' => "You've saved more than you've spent for {$streak} months straight. Great habit — keep it going.",
        'date'    => date('Y-m-d'),
    ];
}

// Brand-new user, no transactions at all yet
if (empty($transactions)) {
    $notifications = [[
        'id' => 'welcome', 'type' => 'info', 'icon' => 'calendar',
        'title'   => 'Welcome to Sentimo',
        'message' => 'Start logging your income and expenses on the Dashboard to unlock personalized insights here.',
        'date'    => date('Y-m-d'),
    ]];
}

// Newest first
usort($notifications, fn($a, $b) => strcmp($b['date'], $a['date']));

$total_count = count($notifications);

// ── SIDE PANEL SUMMARY DATA ─────────────────────────────────────────────
$type_counts = ['info' => 0, 'warning' => 0, 'success' => 0];
foreach ($notifications as $n) {
    if (isset($type_counts[$n['type']])) $type_counts[$n['type']]++;
}

$month_net = $this_month['income'] - $this_month['expense'];
$prev_expense_for_diff = $prev_month['expense'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — Sentimo</title>
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
    [data-theme="light"] .stat-card { background: rgba(255,255,255,.85); border-color: rgba(30,64,175,.1); }
    [data-theme="light"] .stat-label { color: #94A3B8; }
    [data-theme="light"] .theme-toggle { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .notif-btn { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); }
    [data-theme="light"] .topbar-avatar { background: linear-gradient(135deg, var(--blue), var(--indigo)); }
    [data-theme="light"] .notif-card { border-color: rgba(30,64,175,.08); }
    [data-theme="light"] .notif-card:hover { background: rgba(59,130,246,.04); }
    [data-theme="light"] .notif-msg { color: #475569; }
    [data-theme="light"] .notif-date { color: #94A3B8; }
    [data-theme="light"] .mark-all-btn { background: rgba(255,255,255,.7); border-color: rgba(30,64,175,.15); color: #475569; }
    [data-theme="light"] .mark-all-btn:hover { background: rgba(59,130,246,.08); }
    [data-theme="light"] .empty-state { color: #94A3B8; }

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
    .canvas { padding: 18px 20px 40px; display: flex; flex-direction: column; gap: 16px; max-width: 1180px; }

    /* ─── TWO-COLUMN LAYOUT ───────────────────────────────────────────── */
    .notif-grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: start; }
    .notif-main-col { min-width: 0; }
    .notif-side-col { display: flex; flex-direction: column; gap: 12px; position: sticky; top: 24px; }

    /* ─── THIS MONTH MINI CARD (right column) ────────────────────────── */
    .month-card {
        background: linear-gradient(135deg, #1a3a6b 0%, #0f2150 40%, #0d1a3e 100%);
        border: 1px solid rgba(59,130,246,.2);
        border-radius: var(--r-lg);
        padding: 16px 18px;
        position: relative; overflow: hidden;
    }
    .month-card::before { content: ''; position: absolute; top: -40px; right: -40px; width: 160px; height: 160px; background: radial-gradient(circle, rgba(59,130,246,.25) 0%, transparent 70%); pointer-events: none; }
    .month-card-label { font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.5); position: relative; z-index: 1; }
    .month-card-amt { font-size: 1.5rem; font-weight: 800; letter-spacing: -.04em; margin-top: 6px; position: relative; z-index: 1; font-variant-numeric: tabular-nums; }
    .month-card-amt.negative { color: var(--expense-clr); }
    .month-card-chips { display: flex; gap: 6px; margin-top: 12px; position: relative; z-index: 1; }
    .month-chip { flex: 1; padding: 8px 10px; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.1); border-radius: var(--r-sm); text-align: center; }
    .month-chip .mc-lbl { font-size: .6rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .06em; }
    .month-chip .mc-val { font-size: .8rem; font-weight: 700; margin-top: 3px; font-variant-numeric: tabular-nums; }
    .month-chip.inc .mc-val { color: var(--income-clr); }
    .month-chip.exp .mc-val { color: var(--expense-clr); }

    /* ─── INSIGHT BREAKDOWN (right column) ───────────────────────────── */
    .breakdown-list { padding: 4px 0; }
    .breakdown-row { display: flex; align-items: center; gap: 10px; padding: 11px 20px; }
    .breakdown-row:not(:last-child) { border-bottom: 1px solid var(--border); }
    .breakdown-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .breakdown-dot.info    { background: var(--blue-light); }
    .breakdown-dot.warning { background: var(--expense-clr); }
    .breakdown-dot.success { background: var(--income-clr); }
    .breakdown-name { flex: 1; font-size: .78rem; color: var(--text-2); }
    .breakdown-count { font-size: .8rem; font-weight: 700; font-variant-numeric: tabular-nums; }

    @media (max-width: 1024px) {
        .notif-grid { grid-template-columns: 1fr; }
        .notif-side-col { position: static; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    }

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

    /* ─── UNREAD SUMMARY CHIP ────────────────────────────────────────── */
    .unread-chip {
        display: inline-flex; align-items: center; gap: 6px;
        background: var(--blue-dim); color: var(--blue-light);
        padding: 4px 12px; border-radius: 20px;
        font-size: .72rem; font-weight: 700;
    }
    .unread-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--blue-light); }

    .mark-all-btn {
        display: flex; align-items: center; gap: 6px;
        padding: 7px 14px; border-radius: var(--r-sm);
        background: var(--surface); border: 1px solid var(--border);
        color: var(--text-2); font-family: var(--font);
        font-size: .76rem; font-weight: 600; cursor: pointer;
        transition: background .15s, color .15s;
    }
    .mark-all-btn:hover { background: var(--surface-h); color: var(--text-1); }
    .mark-all-btn svg { width: 13px; height: 13px; }
    .mark-all-btn:disabled { opacity: .4; cursor: default; }

    /* ─── NOTIFICATION LIST ──────────────────────────────────────────── */
    .notif-list { display: flex; flex-direction: column; }
    .notif-card {
        display: flex; align-items: flex-start; gap: 14px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        transition: background .15s, opacity .2s;
    }
    .notif-card:last-child { border-bottom: none; }
    .notif-card:hover { background: var(--surface-h); }
    .notif-card.is-read { opacity: .5; }

    .notif-icon {
        width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
        display: grid; place-items: center;
    }
    .notif-icon svg { width: 18px; height: 18px; }
    .notif-icon.info    { background: var(--blue-dim);    color: var(--blue-light); }
    .notif-icon.warning { background: var(--expense-bg);  color: var(--expense-clr); }
    .notif-icon.success { background: var(--income-bg);   color: var(--income-clr); }

    .notif-body { flex: 1; min-width: 0; }
    .notif-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
    .notif-title { font-size: .86rem; font-weight: 700; }
    .notif-date { font-size: .68rem; color: var(--text-3); white-space: nowrap; flex-shrink: 0; }
    .notif-msg { font-size: .78rem; color: var(--text-2); margin-top: 4px; line-height: 1.5; }

    .notif-side { display: flex; flex-direction: column; align-items: center; gap: 8px; flex-shrink: 0; padding-top: 2px; }
    .notif-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--blue); transition: opacity .15s; }
    .notif-card.is-read .notif-unread-dot { opacity: 0; }
    .notif-read-btn {
        width: 26px; height: 26px; border-radius: var(--r-sm);
        background: transparent; border: 1px solid transparent;
        display: grid; place-items: center; cursor: pointer;
        color: var(--text-3);
        transition: background .15s, color .15s, border-color .15s;
        padding: 0;
    }
    .notif-read-btn svg { width: 13px; height: 13px; }
    .notif-card:hover .notif-read-btn { border-color: var(--border); }
    .notif-read-btn:hover { background: var(--blue-dim) !important; color: var(--blue-light) !important; border-color: transparent !important; }
    .notif-card.is-read .notif-read-btn { color: var(--income-clr); }

    /* ─── EMPTY STATE ─────────────────────────────────────────────────── */
    .empty-state {
        padding: 48px 20px; text-align: center; color: var(--text-3);
        display: none; flex-direction: column; align-items: center; gap: 10px;
    }
    .empty-state.visible { display: flex; }
    .empty-state svg { width: 34px; height: 34px; opacity: .5; }
    .empty-state p { font-size: .8rem; }

    @media (max-width: 640px) {
        .sidebar { display: none; }
        .main { margin-left: 0; }
        .canvas { padding: 16px; max-width: 100%; }
        .notif-top { flex-direction: column; gap: 2px; }
        .notif-side-col { grid-template-columns: 1fr; }
    }

    /* ─── AVATAR DROPDOWN ────────────────────────────────────────────── */
    .avatar-wrap { position: relative; }
    .avatar-dropdown {
        position: absolute; top: calc(100% + 8px); right: 0;
        background: #0f1829;
        border: 1px solid var(--border);
        border-radius: var(--r-md);
        min-width: 180px;
        box-shadow: 0 8px 32px rgba(0,0,0,.45);
        overflow: hidden;
        opacity: 0; transform: translateY(-6px) scale(.97);
        pointer-events: none;
        transition: opacity .15s, transform .15s;
        z-index: 999;
    }
    [data-theme="light"] .avatar-dropdown { background: #ffffff; box-shadow: 0 8px 32px rgba(15,23,42,.15); }
    .avatar-dropdown.open { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
    .dd-header {
        padding: 12px 14px 10px;
        border-bottom: 1px solid var(--border);
    }
    .dd-name { font-size: .82rem; font-weight: 700; color: var(--text-1); }
    .dd-user { font-size: .7rem; color: var(--text-3); margin-top: 1px; }
    .dd-item {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px;
        font-size: .8rem; font-weight: 500;
        color: var(--text-2);
        text-decoration: none;
        transition: background .12s, color .12s;
        cursor: pointer;
    }
    .dd-item svg { width: 14px; height: 14px; flex-shrink: 0; }
    .dd-item:hover { background: var(--surface-h); color: var(--text-1); }
    .dd-item.danger { color: var(--expense-clr); }
    .dd-item.danger:hover { background: var(--expense-bg); }
    .dd-divider { height: 1px; background: var(--border); }
    .topbar-avatar { cursor: pointer; transition: opacity .15s, box-shadow .15s; }
    .topbar-avatar:hover { opacity: .88; box-shadow: 0 0 0 3px var(--blue-glow); border-radius: 50%; }
    .notif-btn { cursor: pointer; }

    /* notif-btn as anchor */
    a.notif-btn { display: grid; place-items: center; text-decoration: none; }
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
        <a href="notifications.php" class="sb-btn active">
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
            <h1>Notifications</h1>
            <p>Smart insights auto-generated from your transactions</p>
        </div>
        <div class="page-top-actions">
            <span class="page-greeting">Hi, <span><?php echo htmlspecialchars($display_name); ?>!</span></span>
                    <a href="notifications.php" class="notif-btn" title="Notifications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-dot"></span>
            </a>
            <div class="avatar-wrap" id="avatarWrap">
                    <div class="topbar-avatar" title="Account"><?php echo strtoupper(substr($display_name, 0, 1)); ?></div>
                    <div class="avatar-dropdown" id="avatarDropdown">
                        <div class="dd-header">
                            <div class="dd-name"><?php echo htmlspecialchars($display_name); ?></div>
                            <div class="dd-user">@<?php echo htmlspecialchars($username); ?></div>
                        </div>
                        <a href="settings.php" class="dd-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06-.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            Settings
                        </a>
                        <div class="dd-divider"></div>
                        <a href="logout.php" class="dd-item danger">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            Logout
                        </a>
                    </div>
                </div>
        </div>
    </div>

    <!-- Notifications + side summary -->
    <div class="notif-grid">

        <!-- LEFT: Notifications panel -->
        <div class="notif-main-col">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Your Notifications</h2>
                        <p><span id="unreadCount"><?php echo $total_count; ?></span> unread</p>
                    </div>
                    <button class="mark-all-btn" id="markAllBtn" type="button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Mark all as read
                    </button>
                </div>

                <div class="notif-list" id="notifList">
                    <?php foreach ($notifications as $n): ?>
                    <div class="notif-card" data-id="<?php echo htmlspecialchars($n['id']); ?>">
                        <div class="notif-icon <?php echo htmlspecialchars($n['type']); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icons[$n['icon']]; ?></svg>
                        </div>
                        <div class="notif-body">
                            <div class="notif-top">
                                <h3 class="notif-title"><?php echo htmlspecialchars($n['title']); ?></h3>
                                <span class="notif-date"><?php echo date('M j', strtotime($n['date'])); ?></span>
                            </div>
                            <p class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></p>
                        </div>
                        <div class="notif-side">
                            <span class="notif-unread-dot"></span>
                            <button class="notif-read-btn" title="Mark as read" type="button">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="empty-state" id="emptyState">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <p>No notifications right now. Log a few transactions and check back for fresh insights.</p>
                </div>
            </div>
        </div>

        <!-- RIGHT: Summary sidebar -->
        <div class="notif-side-col">

            <!-- This Month mini balance card -->
            <div class="month-card">
                <div class="month-card-label"><?php echo date('F Y'); ?></div>
                <div class="month-card-amt <?php echo $month_net < 0 ? 'negative' : ''; ?>">
                    <?php echo ($month_net < 0 ? '-' : '') . '₱' . number_format(abs($month_net), 2); ?>
                </div>
                <div class="month-card-chips">
                    <div class="month-chip inc">
                        <div class="mc-lbl">Income</div>
                        <div class="mc-val">₱<?php echo number_format($this_month['income'], 0); ?></div>
                    </div>
                    <div class="month-chip exp">
                        <div class="mc-lbl">Expense</div>
                        <div class="mc-val">₱<?php echo number_format($this_month['expense'], 0); ?></div>
                    </div>
                </div>
            </div>

            <!-- Insight type breakdown -->
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Insight Breakdown</h2>
                        <p>By category, this list</p>
                    </div>
                </div>
                <div class="breakdown-list">
                    <div class="breakdown-row">
                        <span class="breakdown-dot info"></span>
                        <span class="breakdown-name">Informational</span>
                        <span class="breakdown-count"><?php echo $type_counts['info']; ?></span>
                    </div>
                    <div class="breakdown-row">
                        <span class="breakdown-dot warning"></span>
                        <span class="breakdown-name">Warnings</span>
                        <span class="breakdown-count"><?php echo $type_counts['warning']; ?></span>
                    </div>
                    <div class="breakdown-row">
                        <span class="breakdown-dot success"></span>
                        <span class="breakdown-name">Positive</span>
                        <span class="breakdown-count"><?php echo $type_counts['success']; ?></span>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
</div>

<script>
// ── Dark / Light Mode Toggle (same behavior as reports.php) ────────────
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

// ── Notifications: read-state persisted in localStorage, zero backend ──
(function () {
    const STORAGE_KEY = 'sentimo_notifs_read_<?php echo (int) $user_id; ?>';
    const cards        = Array.from(document.querySelectorAll('.notif-card'));
    const unreadCountEl = document.getElementById('unreadCount');
    const markAllBtn    = document.getElementById('markAllBtn');
    const emptyState    = document.getElementById('emptyState');
    const notifList      = document.getElementById('notifList');

    function loadReadIds() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function saveReadIds(ids) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
        } catch (e) { /* storage unavailable, fail silently */ }
    }

    let readIds = new Set(loadReadIds());

    function refreshUI() {
        let unread = 0;
        cards.forEach(function (card) {
            const id = card.dataset.id;
            const isRead = readIds.has(id);
            card.classList.toggle('is-read', isRead);
            if (!isRead) unread++;
        });
        unreadCountEl.textContent = unread;
        markAllBtn.disabled = unread === 0;

        if (cards.length === 0) {
            emptyState.classList.add('visible');
            notifList.style.display = 'none';
        }
    }

    function markRead(id) {
        if (!readIds.has(id)) {
            readIds.add(id);
            saveReadIds(Array.from(readIds));
            refreshUI();
        }
    }

    cards.forEach(function (card) {
        const btn = card.querySelector('.notif-read-btn');
        btn.addEventListener('click', function () {
            markRead(card.dataset.id);
        });
    });

    markAllBtn.addEventListener('click', function () {
        cards.forEach(function (card) { readIds.add(card.dataset.id); });
        saveReadIds(Array.from(readIds));
        refreshUI();
    });

    refreshUI();
})();

// ── Theme: read from localStorage (controlled via Settings) ──────────
(function () {
    const saved = localStorage.getItem('sentimo_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);
})();

// ── Theme: apply on load + sync across tabs ──────────────────────────
(function () {
    const root    = document.documentElement;
    const STORAGE = 'sentimo_theme';
    root.setAttribute('data-theme', localStorage.getItem(STORAGE) || 'dark');
    window.addEventListener('storage', function (e) {
        if (e.key === STORAGE && e.newValue) {
            root.setAttribute('data-theme', e.newValue);
        }
    });
})();

// ── Avatar dropdown ───────────────────────────────────────────────────
(function () {
    const wrap = document.getElementById('avatarWrap');
    const menu = document.getElementById('avatarDropdown');
    if (!wrap || !menu) return;
    wrap.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.classList.toggle('open');
    });
    document.addEventListener('click', function () {
        menu.classList.remove('open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') menu.classList.remove('open');
    });
})();
</script>
</body>
</html>