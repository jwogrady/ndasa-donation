<?php
/**
 * Shared page shell.
 *
 * Callers set $title and $body before including this file.
 * $body is already-escaped HTML; inputs rendered inside the body must pass
 * through Html::h() at their point of use — the layout does not re-escape.
 *
 * @var string $title
 * @var string $body
 */

use NDASA\Support\Html;

$title ??= 'NDASA Foundation';
$body  ??= '';
$base = Html::h(NDASA_BASE_PATH);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= Html::h($title) ?></title>
<link rel="icon" href="<?= $base ?>/assets/img/favicon-32.png" sizes="32x32">
<link rel="icon" href="<?= $base ?>/assets/img/favicon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="<?= $base ?>/assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="<?= $base ?>/assets/css/styles.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container container--wide">
    <a class="site-header__brand" href="https://ndasafoundation.org/">
      <img class="site-header__logo"
           src="<?= $base ?>/assets/img/Foundation-Logo.png"
           alt="NDASA Foundation"
           width="123" height="160"
           decoding="async">
    </a>
  </div>
</header>

<main id="main" class="container">
<?= $body ?>
</main>

<footer class="site-footer">
  <div class="container container--wide">
    <p>
      &copy; <?= date('Y') ?> NDASA Foundation &middot; 501(c)(3) non-profit &middot;
      Payments processed securely by
      <a href="https://stripe.com" rel="noopener noreferrer">Stripe</a>.
    </p>
  </div>
</footer>
</body>
</html>
