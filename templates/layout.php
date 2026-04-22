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
use NDASA\Support\Slogans;

$title ??= 'NDASA Foundation';
$body  ??= '';
$base = Html::h(NDASA_BASE_PATH);

// Header slogan rotator: ship the full list but pick a random starting index
// per page view so the first visible slogan is different across loads.
$sloganList  = Slogans::donor();
$sloganStart = random_int(0, count($sloganList) - 1);
$sloganFirst = $sloganList[$sloganStart];
$sloganJson  = json_encode($sloganList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$nonce       = Html::h(defined('NDASA_CSP_NONCE') ? NDASA_CSP_NONCE : '');
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
<body<?= (defined('NDASA_STRIPE_MODE') && NDASA_STRIPE_MODE === 'test') ? ' class="is-test-mode"' : '' ?>>
<a class="skip" href="#main">Skip to content</a>
<?php if (defined('NDASA_STRIPE_MODE') && NDASA_STRIPE_MODE === 'test'): ?>
  <div class="test-banner" role="alert" aria-live="polite">
    <div class="test-banner__inner">
      <span class="test-banner__chip" aria-hidden="true">
        <span class="test-banner__dot"></span>TEST
      </span>
      <span class="test-banner__msg">
        <strong>Test mode active.</strong>
        Payments are simulated &mdash; no card will be charged.
      </span>
    </div>
  </div>
<?php endif; ?>
<header class="site-header">
  <div class="site-header__inner container container--wide">
    <a class="site-header__brand" href="https://ndasafoundation.org/" aria-label="NDASA Foundation home">
      <img class="site-header__logo"
           src="<?= $base ?>/assets/img/Foundation-Logo.png"
           alt=""
           width="123" height="160"
           decoding="async">
    </a>
    <div class="slogan" role="doc-subtitle" aria-live="polite" aria-atomic="true"
         data-slogan-start="<?= (int) $sloganStart ?>">
      <span class="slogan__lead"><?= Html::h($sloganFirst['lead']) ?></span>
      <span class="slogan__body"><?= Html::h($sloganFirst['body']) ?></span>
      <span class="slogan__rule" aria-hidden="true"></span>
    </div>
    <span class="site-header__section">
      <span class="site-header__section-line">501(c)(3)</span>
      <span class="site-header__section-tag">Donate</span>
    </span>
  </div>
</header>

<main id="main" class="container">
<?= $body ?>
</main>

<footer class="site-footer">
  <div class="site-footer__inner container container--wide">
    <p class="site-footer__tagline">
      <span class="site-footer__eyebrow">Thank you for giving.</span>
      <span class="site-footer__motto">Educating &amp; advocating for drug-free communities.</span>
    </p>
    <p class="site-footer__meta">
      &copy; <?= date('Y') ?> <a href="https://ndasafoundation.org/" class="site-footer__link">NDASA Foundation</a>
      &middot; 501(c)(3) non-profit
      &middot; Payments processed securely by
      <a href="https://stripe.com" class="site-footer__link" rel="noopener noreferrer">Stripe</a>
    </p>
  </div>
</footer>

<script nonce="<?= $nonce ?>" id="ndasa-slogans-data" type="application/json"><?= $sloganJson ?></script>
<script nonce="<?= $nonce ?>">
(function () {
  'use strict';
  var el = document.querySelector('.slogan');
  var data = document.getElementById('ndasa-slogans-data');
  if (!el || !data) return;

  var slogans;
  try { slogans = JSON.parse(data.textContent); }
  catch (e) { return; }
  if (!Array.isArray(slogans) || slogans.length < 2) return;

  var lead = el.querySelector('.slogan__lead');
  var body = el.querySelector('.slogan__body');
  if (!lead || !body) return;

  var start = parseInt(el.getAttribute('data-slogan-start') || '0', 10);
  if (isNaN(start) || start < 0) start = 0;
  var idx = start % slogans.length;

  // prefers-reduced-motion: hold the first pick, no rotation.
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) return;

  // Also pause when the tab is hidden so we don't burn cycles and so the
  // user doesn't return to mid-animation.
  var paused = document.hidden;
  document.addEventListener('visibilitychange', function () { paused = document.hidden; });

  var modes = ['fade', 'drift', 'reveal'];
  var lastMode = null;
  function pickMode() {
    // Random but never repeat the immediately previous mode — keeps the
    // cadence feeling varied even with only three options.
    var choices = modes.filter(function (m) { return m !== lastMode; });
    var next = choices[Math.floor(Math.random() * choices.length)];
    lastMode = next;
    return next;
  }

  function advance() {
    if (paused) return;
    idx = (idx + 1) % slogans.length;
    var s = slogans[idx];
    var mode = pickMode();

    // Remove any prior mode classes, reflow, apply the exit state.
    el.classList.remove('is-fade', 'is-drift', 'is-reveal', 'is-in', 'is-out');
    // Force style flush so the next class change actually transitions.
    void el.offsetWidth;
    el.classList.add('is-' + mode, 'is-out');

    window.setTimeout(function () {
      lead.textContent = s.lead;
      body.textContent = s.body;
      el.classList.remove('is-out');
      el.classList.add('is-in');
    }, 520);

    window.setTimeout(function () {
      el.classList.remove('is-in');
    }, 520 + 820);
  }

  // 7s on screen + ~1.3s transition window.
  window.setInterval(advance, 7000);
})();
</script>
</body>
</html>
