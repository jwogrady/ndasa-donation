<?php
/** @var string $paymentStatus */

use NDASA\Support\Html;

$title = 'Thank you — NDASA Foundation';

// Share / amplify scaffolding. The donor just gave — they're in the highest-
// intent moment they'll ever be in with NDASA. Hand them ways to multiply
// the gift (share, employer matching) before offering a "donate again" CTA
// they're unlikely to take in the same session.
$shareUrl  = 'https://ndasafoundation.org/';
$shareText = 'I just supported the NDASA Foundation. Join me in helping build stronger, healthier communities:';
$twitterHref  = 'https://twitter.com/intent/tweet?text=' . rawurlencode($shareText . ' ' . $shareUrl);
$facebookHref = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($shareUrl);
$linkedinHref = 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($shareUrl);
$mailHref     = 'mailto:?subject=' . rawurlencode('Supporting the NDASA Foundation')
              . '&body=' . rawurlencode($shareText . "\n\n" . $shareUrl);
// doublethedonation.com is the industry-standard employer-matching lookup.
// If NDASA later licenses the widget, replace this link with their embed.
$matchingHref = 'https://doublethedonation.com/matching-gifts/';

ob_start();
?>
<?php if ($paymentStatus === 'paid'): ?>
<div class="gratitude" aria-hidden="true">
  <div class="gratitude__radiance"></div>
  <svg class="gratitude__heart" viewBox="0 0 120 108" width="120" height="108" role="presentation">
    <defs>
      <linearGradient id="gratitude-fill" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%"  stop-color="#9b73d4" />
        <stop offset="65%" stop-color="#623b99" />
        <stop offset="100%" stop-color="#fa5c1e" />
      </linearGradient>
      <linearGradient id="gratitude-stroke" x1="0%" y1="0%" x2="100%" y2="0%">
        <stop offset="0%"  stop-color="#623b99" />
        <stop offset="100%" stop-color="#fa5c1e" />
      </linearGradient>
    </defs>
    <!-- Heart path: classic two-arc with a 60,108 apex, anchored to the
         120x108 viewBox. stroke-dasharray is set via CSS so the outline
         draws in during the first-view animation. -->
    <path class="gratitude__heart-path"
          d="M60 100
             C 8 68, 0 34, 22 14
             C 38 0, 56 6, 60 26
             C 64 6, 82 0, 98 14
             C 120 34, 112 68, 60 100 Z"
          fill="url(#gratitude-fill)"
          stroke="url(#gratitude-stroke)"
          stroke-width="2"
          stroke-linejoin="round"/>
  </svg>
</div>
<?php endif; ?>

<section class="status status--<?= $paymentStatus === 'paid' ? 'ok' : 'pending' ?>">
  <?php if ($paymentStatus === 'paid'): ?>
    <div>
      <h1>Thank you for your support</h1>
      <p class="lede">
        Your donation has been received. A receipt is on its way to your inbox.
      </p>
    </div>

  <?php elseif ($paymentStatus === 'unpaid'): ?>
    <svg class="status__icon" aria-hidden="true" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
    <div>
      <h1>Your donation is being processed</h1>
      <p class="lede">
        Some payment methods take a few business days to clear. We'll email your
        receipt the moment the payment is confirmed — no further action needed.
      </p>
    </div>

  <?php else: ?>
    <svg class="status__icon" aria-hidden="true" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
    <div>
      <h1>We're confirming your donation</h1>
      <p class="lede">
        If you just completed payment, your donation is being processed. A
        receipt will arrive in your inbox once confirmation comes through.
      </p>
    </div>
  <?php endif; ?>
</section>

<section class="next-steps" aria-labelledby="next-heading">
  <h2 id="next-heading">What happens next</h2>
  <ol class="next-steps__list">
    <li>
      <strong>Check your email.</strong>
      Stripe will email a receipt with the transaction details within a few
      minutes. If it doesn't arrive, please check your spam folder before
      contacting us.
    </li>
    <li>
      <strong>Keep the receipt for your records.</strong>
      The NDASA Foundation is a 501(c)(3) non-profit. Your emailed receipt
      serves as documentation for tax purposes — no goods or services were
      provided in exchange for your gift.
    </li>
    <li>
      <strong>We'll put it to work.</strong>
      Every contribution directly supports scholarships, grants, and
      educational programs. Roughly 95 cents of every dollar goes to programs
      and grants; the balance covers essential administration.
      <!-- {{PLACEHOLDER: confirm ratio matches published financials}} -->
    </li>
  </ol>
</section>

<blockquote class="pullquote">
  <p>
    Every donation, regardless of size, makes a difference. Your generosity
    enables us to expand our impact and build stronger, healthier communities.
    On behalf of the students, first responders, and families whose lives are
    changed by this work — thank you.
  </p>
  <p>
    <strong>Together, we can achieve lasting change.</strong>
  </p>
  <footer>
    <strong>James Greer</strong><br>
    <span class="muted">Chairman, Board of Trustees</span>
  </footer>
</blockquote>

<section class="amplify" aria-labelledby="amplify-heading">
  <h2 id="amplify-heading">Multiply your gift</h2>
  <p>
    Your donation is already at work. Here are two ways to turn it into
    more — both take less than a minute.
  </p>

  <div class="amplify__block">
    <h3>Share with someone who cares</h3>
    <p class="muted">
      One forwarded email or post from a trusted friend moves more people
      than any ad we could buy.
    </p>
    <ul class="share-buttons" aria-label="Share NDASA Foundation">
      <li>
        <a class="share share--twitter" href="<?= Html::h($twitterHref) ?>"
           target="_blank" rel="noopener noreferrer">Post on X</a>
      </li>
      <li>
        <a class="share share--facebook" href="<?= Html::h($facebookHref) ?>"
           target="_blank" rel="noopener noreferrer">Share on Facebook</a>
      </li>
      <li>
        <a class="share share--linkedin" href="<?= Html::h($linkedinHref) ?>"
           target="_blank" rel="noopener noreferrer">Share on LinkedIn</a>
      </li>
      <li>
        <a class="share share--email" href="<?= Html::h($mailHref) ?>">Email a friend</a>
      </li>
    </ul>
  </div>

  <div class="amplify__block">
    <h3>Does your employer match?</h3>
    <p class="muted">
      Thousands of companies match charitable donations 1:1 or even 2:1.
      Two minutes of paperwork can double or triple what you just gave.
    </p>
    <p>
      <a class="btn btn--secondary" href="<?= Html::h($matchingHref) ?>"
         target="_blank" rel="noopener noreferrer">
        Check my employer's matching program &rarr;
      </a>
    </p>
  </div>
</section>

<p class="actions">
  <a class="btn btn--primary" href="<?= Html::h(NDASA_BASE_PATH) ?>/">Make another donation</a>
  <a class="btn btn--secondary" href="https://ndasafoundation.org/" rel="noopener">
    Return to ndasafoundation.org
  </a>
</p>

<p class="muted fineprint">
  Receipt not arriving? Contact
  <a href="mailto:info@ndasafoundation.org">info@ndasafoundation.org</a>
  with the approximate time of your donation and we'll help you track it down.
</p>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
