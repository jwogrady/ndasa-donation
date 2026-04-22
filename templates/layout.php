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
  <!-- Top contact strip: phone left, social right. Mirrors the
       purple → red → purple gradient bar on ndasafoundation.org so the
       donation page reads as a continuation of the same site. -->
  <div class="site-header__strip">
    <div class="site-header__strip-inner container container--wide">
      <a class="site-header__phone" href="tel:+18883163272" aria-label="Call NDASA Foundation">
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">
          <path d="M20 15.5a17.5 17.5 0 0 1-5.4-.85 1.5 1.5 0 0 0-1.52.37l-2.2 2.2a15 15 0 0 1-6.6-6.6l2.2-2.2a1.5 1.5 0 0 0 .38-1.52A17.5 17.5 0 0 1 6 1.5 1.5 1.5 0 0 0 4.5 0H1.5A1.5 1.5 0 0 0 0 1.5 20 20 0 0 0 20 21.5 1.5 1.5 0 0 0 21.5 20v-3a1.5 1.5 0 0 0-1.5-1.5z"/>
        </svg>
        <span>888-316-3272</span>
      </a>
      <ul class="site-header__social" aria-label="NDASA Foundation on social">
        <li><a href="https://facebook.com/NDASAFoundation" aria-label="Facebook" rel="noopener">
          <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">
            <path d="M13 22v-8h3l.5-4H13V7.5c0-1.2.3-2 2-2h2V2.1c-.3 0-1.5-.1-2.8-.1-2.8 0-4.7 1.7-4.7 4.8V10H7v4h2.5v8H13z"/>
          </svg>
        </a></li>
        <li><a href="https://twitter.com/NDASAFoundation" aria-label="X / Twitter" rel="noopener">
          <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">
            <path d="M18.2 2H21l-6.4 7.3L22.5 22H16l-5-6.6L5 22H2l6.8-7.8L1.7 2h6.6l4.6 6.1L18.2 2zm-1 18h1.6L6.8 3.8H5L17.2 20z"/>
          </svg>
        </a></li>
        <li><a href="https://linkedin.com/company/ndasafoundation" aria-label="LinkedIn" rel="noopener">
          <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">
            <path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3V9zm7 0h3.8v1.7h.1c.5-1 1.9-2 3.8-2 4.1 0 4.8 2.7 4.8 6.2V21h-4v-5.5c0-1.3 0-3-1.8-3s-2.1 1.4-2.1 2.9V21h-4V9z"/>
          </svg>
        </a></li>
        <li><a href="https://youtube.com/@ndasafoundation" aria-label="YouTube" rel="noopener">
          <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">
            <path d="M23 7.5s-.2-1.6-.9-2.3c-.9-.9-1.8-.9-2.3-1C16.4 4 12 4 12 4s-4.4 0-7.8.2c-.5.1-1.4.1-2.3 1C1.2 5.9 1 7.5 1 7.5S.8 9.4.8 11.3v1.4C.8 14.6 1 16.5 1 16.5s.2 1.6.9 2.3c.9.9 2.1.9 2.6 1 1.9.2 8 .2 8 .2s4.4 0 7.8-.2c.5-.1 1.4-.1 2.3-1 .7-.7.9-2.3.9-2.3s.2-1.9.2-3.8v-1.4c0-1.9-.2-3.8-.2-3.8zM9.8 15V8.3l5.8 3.4L9.8 15z"/>
          </svg>
        </a></li>
      </ul>
    </div>
  </div>

  <!-- Main band: pale aqua, three regions.
       Left   = logo + wordmark + subtitle (matches ndasafoundation.org)
       Center = rotating slogan (replaces the parent site's nav — we
                don't want donors bouncing mid-funnel)
       Right  = "501(C)(3) / DONATE" section label -->
  <div class="site-header__main">
    <div class="site-header__main-inner container container--wide">
      <a class="site-header__brand" href="https://ndasafoundation.org/" aria-label="NDASA Foundation home">
        <img class="site-header__logo"
             src="<?= $base ?>/assets/img/Foundation-Logo.png"
             alt=""
             width="123" height="160"
             decoding="async">
        <span class="site-header__wordmark">
          <span class="site-header__name">NDASA Foundation</span>
          <span class="site-header__subtitle">501 (c)3 Non-Profit Organization</span>
        </span>
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
  </div>
</header>

<main id="main" class="container">
<?= $body ?>
</main>

<footer class="site-footer">
  <!-- Main band: mirrors the header's aqua band so the page bookends
       cleanly. The orange top border picks up the header's bottom
       border and "you're in the donation flow" cue. -->
  <div class="site-footer__main">
    <div class="site-footer__inner container container--wide">
      <p class="site-footer__tagline">
        <span class="site-footer__eyebrow">Thank you for giving.</span>
        <span class="site-footer__motto">Educating &amp; advocating for healthier, safer communities.</span>
      </p>
      <p class="site-footer__meta">
        &copy; <?= date('Y') ?> <a href="https://ndasafoundation.org/" class="site-footer__link">NDASA Foundation</a>
        &middot; 501(c)(3) non-profit
        &middot; Payments processed securely by
        <a href="https://stripe.com" class="site-footer__link" rel="noopener noreferrer">Stripe</a>
      </p>
    </div>
  </div>
  <!-- Bottom strip: deep purple, mirrors the header's top contact strip.
       Quick nav back to the parent site + security reassurance. -->
  <div class="site-footer__strip">
    <div class="site-footer__strip-inner container container--wide">
      <span class="site-footer__strip-left">
        <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" fill="currentColor">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4" fill="none" stroke="currentColor" stroke-width="2"/>
        </svg>
        Secure checkout via Stripe
      </span>
      <span class="site-footer__strip-right">
        <a href="https://ndasafoundation.org/">Home</a>
        <a href="https://ndasafoundation.org/about/">About</a>
        <a href="https://ndasafoundation.org/contact/">Contact</a>
      </span>
    </div>
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
