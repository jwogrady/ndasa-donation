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
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= Html::h($title) ?></title>
<link rel="stylesheet" href="<?= Html::h(NDASA_BASE_PATH) ?>/assets/css/styles.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container container--wide">
    <a class="site-header__brand" href="<?= Html::h(NDASA_BASE_PATH) ?>/">
      <span class="site-header__mark" aria-hidden="true">ND</span>
      <span>
        <strong>NDASA Foundation</strong>
        <span class="site-header__sub">501(c)(3) non-profit</span>
      </span>
    </a>
  </div>
</header>

<main id="main" class="container">
<?= $body ?>
</main>

<footer class="site-footer">
  <div class="container container--wide">
    <p>
      &copy; <?= date('Y') ?> NDASA Foundation. Payments processed securely by
      <a href="https://stripe.com" rel="noopener noreferrer">Stripe</a>.
    </p>
  </div>
</footer>
</body>
</html>
