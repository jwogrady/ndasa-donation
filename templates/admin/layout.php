<?php
/**
 * Admin shell layout.
 *
 * @var string $title    Page title shown in the <title> and <h1>.
 * @var string $body     Pre-rendered body HTML (escaping is the caller's
 *                        responsibility — templates must run values through
 *                        NDASA\Support\Html::h() before interpolating).
 * @var string $active   Optional nav key: "dashboard" or "config".
 */

use NDASA\Support\Html;

$title  ??= 'NDASA Admin';
$body   ??= '';
$active ??= '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Html::h($title) ?> &mdash; NDASA Admin</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1a1a1a; background: #f6f6f6; }
  header { background: #1a1a1a; color: #fff; padding: 12px 24px; display: flex; align-items: center; gap: 32px; }
  header h1 { margin: 0; font-size: 16px; font-weight: 600; letter-spacing: 0.3px; }
  nav { display: flex; gap: 16px; }
  nav a { color: #ccc; text-decoration: none; padding: 4px 8px; border-radius: 3px; }
  nav a:hover { color: #fff; background: #333; }
  nav a.active { color: #fff; background: #333; }
  main { max-width: 960px; margin: 32px auto; padding: 0 24px; }
  main h1 { font-size: 22px; margin: 0 0 24px; }
  main h2 { font-size: 16px; margin: 32px 0 12px; color: #555; text-transform: uppercase; letter-spacing: 0.5px; }
  .panel { background: #fff; border: 1px solid #e2e2e2; border-radius: 4px; padding: 20px 24px; }
  .panel + .panel { margin-top: 16px; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
  .stat { background: #fff; border: 1px solid #e2e2e2; border-radius: 4px; padding: 16px 20px; }
  .stat__label { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
  .stat__value { font-size: 24px; font-weight: 600; margin-top: 4px; color: #1a1a1a; }
  form label { display: block; margin-bottom: 16px; }
  form .label-text { display: block; font-weight: 600; margin-bottom: 4px; }
  form .help { display: block; color: #666; font-size: 13px; margin-top: 4px; }
  form input[type="text"], form input[type="url"], form input[type="email"], form input[type="password"] {
    width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 3px; font: inherit;
  }
  form button { background: #1a1a1a; color: #fff; border: 0; padding: 10px 18px; border-radius: 3px; cursor: pointer; font: inherit; }
  form button:hover { background: #333; }
  .notice { padding: 12px 16px; border-radius: 3px; margin-bottom: 24px; }
  .notice--ok  { background: #e8f5e9; border: 1px solid #b7dfc0; color: #1b5e20; }
  .notice--err { background: #fdecea; border: 1px solid #f5c2bc; color: #7f1d1d; }
  .muted { color: #666; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; }
  th { color: #666; font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
  tr.empty td { text-align: center; color: #888; padding: 32px 10px; }
</style>
</head>
<body>
<header>
  <h1>NDASA Admin</h1>
  <nav>
    <a href="/admin" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="/admin/config" class="<?= $active === 'config' ? 'active' : '' ?>">Config</a>
  </nav>
</header>
<main>
<?= $body ?>
</main>
</body>
</html>
