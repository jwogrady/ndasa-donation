<?php
/** @var string $paymentStatus */

$title = 'Thank you — NDASA Foundation';

ob_start();
?>
<section class="status status--<?= $paymentStatus === 'paid' ? 'ok' : 'pending' ?>">
  <?php if ($paymentStatus === 'paid'): ?>
    <svg class="status__icon" aria-hidden="true" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
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

<section class="stay-connected" aria-labelledby="stay-heading">
  <h2 id="stay-heading">Stay connected</h2>
  <p>
    Want to see the impact of your gift? We share stories from scholarship
    recipients, grantee organizations, and community programs throughout the
    year.
  </p>
  <ul class="stay-connected__list">
    <li>
      <a href="https://ndasafoundation.org" rel="noopener">
        Visit ndasafoundation.org
      </a>
      for news and program updates.
    </li>
    <li>
      <!-- {{PLACEHOLDER: real newsletter signup URL/form}} -->
      Email <a href="mailto:info@ndasafoundation.org">info@ndasafoundation.org</a>
      to join our newsletter or request information about our programs.
    </li>
    <li>
      Ask your employer about matching gifts to double your impact.
    </li>
  </ul>
</section>

<p class="actions">
  <a class="btn btn--primary" href="<?= \NDASA\Support\Html::h(NDASA_BASE_PATH) ?>/">Make another donation</a>
  <a class="btn btn--secondary" href="https://ndasafoundation.org" rel="noopener">
    Return to main site
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
