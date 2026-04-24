<?php
/**
 * Admin shell layout.
 *
 * @var string $title       Page title shown in the <title> and <h1>.
 * @var string $body        Pre-rendered body HTML (escaping is the caller's
 *                           responsibility — templates must run values through
 *                           NDASA\Support\Html::h() before interpolating).
 * @var string $active      Optional nav key: "dashboard", "transactions", "subscriptions", "donors", or "diagnostics".
 * @var string $appVersion  Resolved app version string for the footer.
 */

use NDASA\Support\Html;

$title      ??= 'NDASA Admin';
$body       ??= '';
$active     ??= '';
$appVersion ??= '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Html::h($title) ?> &mdash; NDASA Admin</title>
<style nonce="<?= Html::h(NDASA_CSP_NONCE) ?>">
  /* Admin chrome — dimmed palette, high-contrast readability. Purple/orange
     accents match the donor funnel so admin feels like the same product.
     Roboto Condensed falls back to system fonts if not installed locally;
     admin pages don't load the funnel's self-hosted woff2 files. */
  :root {
    color-scheme: dark;
    --bg:         #2a2d35;
    --surface:    #363a44;  /* panels, stats, inputs */
    --surface-2:  #2f333c;  /* table stripe / disabled */
    --topbar:     #1a1c22;
    --border:     #454a55;
    --ink:        #e8eaed;  /* body copy */
    --muted:      #a8adb8;
    --dim:        #7d838f;
    --brand:      #9b73d4;  /* lifted purple for dark-bg contrast; origin #623b99 */
    --brand-deep: #623b99;
    --cta:        #fa5c1e;
    --cta-hover:  #ff7a44;
    --ok-bg:      #1f3527;
    --ok-border:  #2e5a3d;
    --ok-ink:     #7cc68b;
    --err-bg:     #3a2424;
    --err-border: #6b3535;
    --err-ink:    #ef6f6a;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 15px/1.55 'Poppins', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: var(--ink);
    background: var(--bg);
  }
  header {
    background: var(--topbar);
    color: #fff;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    border-bottom: 1px solid var(--border);
  }
  .admin-brand {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    color: #fff;
    text-decoration: none;
  }
  .admin-brand__logo {
    display: block;
    width: auto;
    height: 40px;
    max-width: 100%;
  }
  .admin-brand__label {
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 16px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #fff;
  }
  nav { display: flex; gap: 8px; }
  nav a {
    color: var(--muted);
    text-decoration: none;
    padding: 6px 12px;
    border-radius: 4px;
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-size: 13px;
    font-weight: 700;
    transition: color 120ms, background 120ms;
  }
  nav a:hover   { color: #fff; background: #2c2f38; }
  nav a.active  { color: #fff; background: var(--brand-deep); }
  main { max-width: 960px; margin: 32px auto; padding: 0 24px; }
  main h1 {
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 26px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 24px;
    color: #fff;
  }
  main h2 {
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 14px;
    margin: 32px 0 12px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
  }
  a { color: var(--brand); }
  a:hover { color: var(--cta); }
  .panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 20px 24px;
  }
  .panel + .panel { margin-top: 16px; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
  .stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 16px 20px;
  }
  .stat__label {
    color: var(--muted);
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .stat__value {
    font-size: 26px;
    font-weight: 700;
    margin-top: 6px;
    color: #fff;
  }
  form label { display: block; margin-bottom: 16px; }
  form .label-text { display: block; font-weight: 600; margin-bottom: 4px; color: var(--ink); }
  form .help { display: block; color: var(--muted); font-size: 13px; margin-top: 4px; }
  form input[type="text"],
  form input[type="url"],
  form input[type="email"],
  form input[type="password"],
  form input[type="date"],
  form input[type="number"],
  form select {
    width: 100%;
    padding: 9px 12px;
    background: var(--surface-2);
    color: var(--ink);
    border: 1px solid var(--border);
    border-radius: 4px;
    font: inherit;
    transition: border-color 120ms, box-shadow 120ms;
  }
  form input:focus {
    outline: none;
    border-color: var(--cta);
    box-shadow: 0 0 0 3px rgba(250, 92, 30, 0.25);
  }
  form button {
    background: var(--cta);
    color: #fff;
    border: 0;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font: inherit;
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-size: 14px;
    transition: background 160ms;
  }
  form button:hover { background: var(--cta-hover); }
  .notice { padding: 12px 16px; border-radius: 4px; margin-bottom: 24px; }
  .notice--ok  { background: var(--ok-bg);  border: 1px solid var(--ok-border);  color: var(--ok-ink); }
  .notice--err { background: var(--err-bg); border: 1px solid var(--err-border); color: var(--err-ink); }
  .muted { color: var(--muted); }
  .req   { color: var(--err-ink); font-weight: 700; margin-left: 0.15rem; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); }
  th {
    color: var(--muted);
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
    background: var(--surface-2);
  }
  tr.empty td { text-align: center; color: var(--dim); padding: 32px 10px; }
  footer {
    max-width: 960px;
    margin: 48px auto 24px;
    padding: 16px 24px 0;
    border-top: 1px solid var(--border);
    color: var(--dim);
    font-size: 12px;
  }
  .health-status-ok {
    color: var(--ok-ink);
    font-weight: 700;
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .health-status-fail {
    color: var(--err-ink);
    font-weight: 700;
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    text-transform: uppercase;
    letter-spacing: 1px;
    background: var(--err-bg);
    padding: 2px 8px;
    border-radius: 3px;
  }
  .health-detail-fail { color: var(--err-ink); }
  .panel-intro { margin-top: 0; color: var(--muted); }
  .panel-subheading {
    margin: 0 0 12px;
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 13px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
  }
  .col-check  { width: 40%; }
  .col-status { width: 15%; }
  .fineprint  { margin-top: 24px; color: var(--dim); }
  .export-form {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    margin: 0 0 16px;
  }
  .export-form label { margin: 0; }
  .export-form input[type="date"] { width: auto; min-width: 160px; }
  .export-form .help { flex-basis: 100%; margin: 0; }
  .export-form button { margin-top: 0; }

  /* Recent-donations filter note. Sits between the heading and the table so
     "the current Stripe mode is filtering this data" is stated once, clearly,
     rather than inferred from the mode panel up top. */
  .mode-filter-note {
    margin: -4px 0 12px;
    font-size: 0.9rem;
  }
  .mode-filter-note strong { letter-spacing: 0.08em; }

  /* Index-page utilities — shared across /admin/transactions,
     /admin/subscriptions, /admin/donors. Kept close together so any tweak
     flows through all three indexes with one CSS edit. */
  .index-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin: 0 0 16px;
    padding: 14px;
    background: var(--panel-bg, #1a1d24);
    border: 1px solid var(--border, #2a2f38);
    border-radius: 8px;
  }
  .index-filters label { margin: 0; display: flex; flex-direction: column; gap: 4px; font-size: 0.85rem; }
  .index-filters input, .index-filters select {
    padding: 6px 8px;
    min-width: 140px;
    background: #0f1117;
    color: inherit;
    border: 1px solid var(--border, #2a2f38);
    border-radius: 4px;
  }
  .index-filters button { margin: 0; }
  .index-filters .clear { align-self: flex-end; color: var(--muted, #8a92a1); text-decoration: none; font-size: 0.85rem; }
  .index-filters .clear:hover { color: #fff; }

  .pager {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin: 12px 0;
    font-size: 0.9rem;
    color: var(--muted, #8a92a1);
  }
  .pager__links a, .pager__links .disabled {
    display: inline-block;
    padding: 4px 10px;
    margin-left: 4px;
    border: 1px solid var(--border, #2a2f38);
    border-radius: 4px;
    text-decoration: none;
    color: inherit;
  }
  .pager__links a:hover { background: #2c2f38; color: #fff; }
  .pager__links .disabled { opacity: 0.4; pointer-events: none; }

  /* Donor detail header card — at-a-glance identity + lifetime totals. */
  .donor-header {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 20px;
    padding: 18px;
    background: var(--panel-bg, #1a1d24);
    border: 1px solid var(--border, #2a2f38);
    border-radius: 8px;
    margin-bottom: 20px;
  }
  .donor-header__stat { text-align: right; }
  .donor-header__stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted, #8a92a1); }
  .donor-header__stat-value { font-size: 1.5rem; font-weight: 600; }
  .donor-header__email { color: var(--muted, #8a92a1); font-size: 0.95rem; }
  .donor-header__optin { margin-top: 8px; font-size: 0.85rem; }
  .donor-header__optin--yes { color: #7cc68b; }
  .donor-header__optin--no  { color: #c29632; }

  /* Pulse grid — four operational tiles (heartbeat, recurring, sparkline,
     refund rate). Lives between the main stats row and the recent-donations
     table. Each tile shows a label, a primary value, and a one-line caption.
     Traffic-light colors (ok/warn/bad/gone) apply a left border; the base
     tile stays neutral so information density reads first. */
  .pulse {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin: 0 0 24px;
  }
  .pulse__tile {
    background: var(--panel-bg, #1a1d24);
    border: 1px solid var(--border, #2a2f38);
    border-left-width: 3px;
    border-radius: 8px;
    padding: 14px 16px;
    min-height: 100px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
  }
  .pulse__tile--wide { grid-column: span 2; }
  /* Status tints on the left edge — readable without relying on color alone
     because each tile also has a text label and sub-line. */
  .pulse__tile--ok   { border-left-color: #4fa468; }
  .pulse__tile--warn { border-left-color: #c29632; }
  .pulse__tile--bad  { border-left-color: #c0504d; }
  .pulse__tile--gone { border-left-color: #6a6f7a; }
  .pulse__label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted, #8a92a1);
    margin-bottom: 6px;
  }
  .pulse__value {
    font-size: 1.35rem;
    font-weight: 600;
    line-height: 1.2;
  }
  .pulse__unit {
    font-size: 0.75rem;
    color: var(--muted, #8a92a1);
    font-weight: 400;
    margin-left: 2px;
  }
  .pulse__sub {
    margin-top: auto;
    padding-top: 6px;
    font-size: 0.8rem;
    color: var(--muted, #8a92a1);
  }
  /* Heartbeat tile: one line per mode, colored by that mode's freshness so
     test-pipe health can't disguise live-pipe silence. */
  .hb-mode { display: block; line-height: 1.35; }
  .hb-mode--ok   { color: #4fa468; }
  .hb-mode--warn { color: #c29632; }
  .hb-mode--bad  { color: #c0504d; }
  .hb-mode--gone { color: #6a6f7a; }
  .pulse__spark {
    display: block;
    width: 100%;
    height: 40px;
    margin: 6px 0 2px;
    color: #7cc68b; /* stroke picks this up via currentColor */
    opacity: 0.9;
  }

  /* Mobile / narrow viewport: two-up then one-up. */
  @media (max-width: 900px) {
    .pulse { grid-template-columns: repeat(2, 1fr); }
    .pulse__tile--wide { grid-column: span 2; }
  }
  @media (max-width: 520px) {
    .pulse { grid-template-columns: 1fr; }
    .pulse__tile--wide { grid-column: auto; }
  }

  /* Stripe mode panel — live is calm/green, test is loud/amber. The pulse
     dot and hairline top border make the current mode unmistakable at a
     glance without relying on color alone. */
  .mode-panel {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 18px 22px;
    margin: 0 0 20px;
    border-radius: 8px;
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
  }
  .mode-panel::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 3px;
  }
  .mode-panel--live { background: linear-gradient(135deg, #1f3527 0%, #263a2d 100%); border-color: var(--ok-border); }
  .mode-panel--live::before { background: linear-gradient(90deg, #7cc68b 0%, #4fa468 100%); }
  .mode-panel--test { background: linear-gradient(135deg, #3a2e15 0%, #41351a 100%); border-color: #7a5f22; }
  .mode-panel--test::before {
    background: repeating-linear-gradient(45deg,
      #f5b942 0 12px,
      #1a1c22 12px 24px);
  }
  .mode-panel__left { display: flex; align-items: center; gap: 16px; }
  .mode-panel__pulse {
    width: 14px; height: 14px; border-radius: 50%;
    flex-shrink: 0;
  }
  .mode-panel--live .mode-panel__pulse {
    background: #7cc68b;
    box-shadow: 0 0 0 0 rgba(124,198,139,.7);
    animation: mode-pulse-ok 2.4s ease-out infinite;
  }
  .mode-panel--test .mode-panel__pulse {
    background: #f5b942;
    box-shadow: 0 0 0 0 rgba(245,185,66,.7);
    animation: mode-pulse-warn 1.6s ease-out infinite;
  }
  @keyframes mode-pulse-ok   { 0% { box-shadow: 0 0 0 0 rgba(124,198,139,.7); } 70% { box-shadow: 0 0 0 12px rgba(124,198,139,0); } 100% { box-shadow: 0 0 0 0 rgba(124,198,139,0); } }
  @keyframes mode-pulse-warn { 0% { box-shadow: 0 0 0 0 rgba(245,185,66,.7);  } 70% { box-shadow: 0 0 0 14px rgba(245,185,66,0);  } 100% { box-shadow: 0 0 0 0 rgba(245,185,66,0);  } }
  @media (prefers-reduced-motion: reduce) {
    .mode-panel__pulse { animation: none; }
  }
  .mode-panel__eyebrow {
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 11px;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 2px;
  }
  .mode-panel__title {
    margin: 0;
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 2px;
  }
  .mode-panel--live .mode-panel__title { color: #9bdcab; }
  .mode-panel--test .mode-panel__title { color: #ffd37a; }
  .mode-panel__sub { margin: 4px 0 0; color: var(--ink); font-size: 13px; max-width: 48ch; }
  .mode-panel__form { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
  .mode-panel__btn {
    font-family: 'Roboto Condensed', system-ui, sans-serif;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 10px 18px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    color: #fff;
    background: var(--cta);
    transition: background .12s ease;
  }
  .mode-panel__btn:hover:not([disabled]) { background: var(--cta-hover); }
  .mode-panel__btn[disabled] {
    opacity: .45;
    cursor: not-allowed;
    background: var(--surface-2);
    color: var(--muted);
    border-color: var(--border);
  }
  .mode-panel__hint { margin: 0; font-size: 11px; color: var(--muted); text-align: right; }
  .mode-panel__hint code { background: rgba(255,255,255,.06); padding: 1px 4px; border-radius: 3px; }
  @media (max-width: 620px) {
    .mode-panel { flex-direction: column; align-items: stretch; }
    .mode-panel__form { align-items: stretch; }
    .mode-panel__hint { text-align: left; }
  }

  /* Diagnostics — tile grid with colored left borders. Same 4-status system
     (ok/warn/bad/gone) as the pulse heartbeat so a broken Stripe account
     and a stale webhook look the same at a glance. */
  .diag-section { margin: 24px 0; }
  .diag-anchors {
    display: flex; flex-wrap: wrap; gap: 4px 16px;
    margin: -8px 0 24px;
    font-size: 12px;
  }
  .diag-anchors a { color: var(--muted); text-decoration: none; padding: 2px 0; }
  .diag-anchors a:hover { color: var(--cta); }
  .diag-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 10px;
  }
  .diag-tile {
    background: var(--surface);
    border: 1px solid var(--border);
    border-left-width: 3px;
    border-radius: 6px;
    padding: 10px 14px;
    min-height: 68px;
    display: flex; flex-direction: column; justify-content: flex-start;
  }
  .diag-tile--ok   { border-left-color: #4fa468; }
  .diag-tile--warn { border-left-color: #c29632; }
  .diag-tile--bad  { border-left-color: #c0504d; }
  .diag-tile--gone { border-left-color: #6a6f7a; }
  .diag-tile__label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.07em; color: var(--muted); margin-bottom: 4px;
  }
  .diag-tile__value {
    font-size: 0.95rem; font-weight: 600; color: var(--ink);
    word-break: break-word;
  }
  .diag-tile__detail {
    margin-top: 4px; font-size: 11px; color: var(--dim);
    word-break: break-word;
  }
  .diag-logtail {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px; line-height: 1.45;
    color: var(--ink);
    max-height: 360px;
    overflow: auto;
    white-space: pre;
  }
</style>
</head>
<body>
<header>
  <a class="admin-brand" href="https://ndasafoundation.org/">
    <img class="admin-brand__logo"
         src="<?= Html::h(NDASA_BASE_PATH) ?>/assets/img/Foundation-Logo.png"
         alt="NDASA Foundation"
         width="31" height="40"
         decoding="async">
    <span class="admin-brand__label">Admin</span>
  </a>
  <nav>
    <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/transactions" class="<?= $active === 'transactions' ? 'active' : '' ?>">Transactions</a>
    <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/subscriptions" class="<?= $active === 'subscriptions' ? 'active' : '' ?>">Subscriptions</a>
    <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/donors" class="<?= $active === 'donors' ? 'active' : '' ?>">Donors</a>
    <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/diagnostics" class="<?= $active === 'diagnostics' ? 'active' : '' ?>">Diagnostics</a>
  </nav>
</header>
<main>
<?= $body ?>
</main>
<footer>
  <?php if ($appVersion !== ''): ?>
    Version: <?= Html::h($appVersion) ?>
  <?php endif; ?>
</footer>
</body>
</html>
