<?php
/**
 * @var string $csrf
 * @var bool   $canceled
 */

use NDASA\Http\Csrf;
use NDASA\Support\Html;

$title = 'Donate — NDASA Foundation';

ob_start();
?>
<section class="hero">
  <h1>Help build healthier, drug-free communities</h1>
  <p class="lede">
    The NDASA Foundation advances prevention, education, and recovery across
    the country. Your gift funds scholarships for students, grants for first
    responders and community nonprofits, and educational programs that reach
    people where they live, learn, and work.
  </p>
  <p class="hero__eyebrow">
    <span class="badge">501(c)(3) non-profit</span>
    <span class="badge badge--muted">Tax-deductible</span>
    <span class="badge badge--muted">Stripe-secured</span>
  </p>
</section>

<?php if (!empty($canceled)): ?>
  <div class="notice" role="status" aria-live="polite">
    Payment canceled. Your card was not charged — you can try again any time.
  </div>
<?php endif; ?>

<section class="impact" aria-labelledby="impact-heading">
  <h2 id="impact-heading">What your gift makes possible</h2>
  <ul class="impact-grid">
    <li class="impact-card">
      <div class="impact-card__amount">$25</div>
      <p>
        <!-- {{PLACEHOLDER: confirm specific impact}} -->
        Provides prevention and awareness materials to one classroom or
        community group.
      </p>
    </li>
    <li class="impact-card">
      <div class="impact-card__amount">$100</div>
      <p>
        <!-- {{PLACEHOLDER: confirm specific impact}} -->
        Helps sponsor a first responder or community nonprofit with
        training resources that directly reach people in crisis.
      </p>
    </li>
    <li class="impact-card">
      <div class="impact-card__amount">$500</div>
      <p>
        <!-- {{PLACEHOLDER: confirm specific impact}} -->
        Underwrites a student scholarship toward education focused on
        substance-use prevention, treatment, or recovery.
      </p>
    </li>
  </ul>
</section>

<section class="allocation" aria-labelledby="allocation-heading">
  <h2 id="allocation-heading">Where your donation goes</h2>
  <p class="muted">
    <!-- {{PLACEHOLDER: replace with real audited allocation from annual report}} -->
    Every dollar is directed toward programs and grants, with minimal overhead.
    The NDASA Foundation is governed by a volunteer board of trustees and
    publishes its financials annually.
  </p>
  <ul class="allocation-bars">
    <li>
      <div class="allocation-bars__label"><span>Scholarships &amp; grants</span><strong>78%</strong></div>
      <div class="allocation-bars__track"><div class="allocation-bars__fill" style="width:78%"></div></div>
    </li>
    <li>
      <div class="allocation-bars__label"><span>Education &amp; outreach</span><strong>17%</strong></div>
      <div class="allocation-bars__track"><div class="allocation-bars__fill" style="width:17%"></div></div>
    </li>
    <li>
      <div class="allocation-bars__label"><span>Administration</span><strong>5%</strong></div>
      <div class="allocation-bars__track"><div class="allocation-bars__fill" style="width:5%"></div></div>
    </li>
  </ul>
</section>

<form class="donation-form" method="post" action="/checkout" novalidate>
  <input type="hidden" name="<?= Html::h(Csrf::FIELD) ?>" value="<?= Html::h($csrf) ?>">

  <fieldset class="amount-group">
    <legend>Choose an amount <span class="req" aria-hidden="true">*</span></legend>

    <div class="presets" role="radiogroup" aria-label="Preset donation amounts">
      <?php foreach ([25, 50, 100, 250, 500] as $preset): ?>
        <label class="preset">
          <input type="radio" name="preset" value="<?= $preset ?>" data-preset>
          <span>$<?= $preset ?></span>
        </label>
      <?php endforeach; ?>
      <label class="preset preset--other">
        <input type="radio" name="preset" value="other" data-preset checked>
        <span>Other</span>
      </label>
    </div>

    <label class="amount-label" for="amount">
      Amount (USD)
      <span class="req" aria-hidden="true">*</span>
    </label>
    <div class="amount-input">
      <span class="amount-input__prefix" aria-hidden="true">$</span>
      <input
        type="number"
        id="amount"
        name="amount"
        min="10"
        max="10000"
        step="0.01"
        inputmode="decimal"
        autocomplete="off"
        aria-describedby="amount-help"
        placeholder="Other amount"
      >
    </div>
    <small id="amount-help">Minimum $10.00. Maximum $10,000.00 per transaction.</small>
  </fieldset>

  <fieldset>
    <legend>Your details</legend>

    <div class="row">
      <label for="fname">
        First name <span class="req" aria-hidden="true">*</span>
        <input type="text" id="fname" name="fname" maxlength="100" required autocomplete="given-name">
      </label>
      <label for="lname">
        Last name <span class="req" aria-hidden="true">*</span>
        <input type="text" id="lname" name="lname" maxlength="100" required autocomplete="family-name">
      </label>
    </div>

    <label for="email">
      Email <span class="req" aria-hidden="true">*</span>
      <input type="email" id="email" name="email" maxlength="254" required autocomplete="email" aria-describedby="email-help">
    </label>
    <small id="email-help">Your receipt will be sent here.</small>

    <label for="phone">
      Phone <span class="muted">(optional)</span>
      <input type="tel" id="phone" name="phone" maxlength="30" autocomplete="tel">
    </label>
  </fieldset>

  <fieldset class="fees">
    <legend>Help cover processing fees?</legend>
    <p class="fees__note">
      Card processing costs 2.9% + $0.30 per transaction. Covering it means 100% of
      your intended gift reaches our programs.
    </p>
    <div class="fees__options">
      <label class="inline">
        <input type="radio" name="cover_fees" value="yes">
        Yes, I'll cover the fee
      </label>
      <label class="inline">
        <input type="radio" name="cover_fees" value="no" checked>
        No thanks
      </label>
    </div>
  </fieldset>

  <p id="total-preview" class="total-preview" hidden aria-live="polite"></p>

  <button type="submit" class="btn btn--primary">
    Continue to secure checkout &rarr;
  </button>

  <p class="fineprint">
    <svg class="lock" aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    You will be redirected to Stripe's secure checkout. Card details are entered
    there and never touch our servers.
  </p>
</form>

<aside class="tax-note" aria-label="Tax information">
  <h2>Tax-deductibility</h2>
  <p>
    The NDASA Foundation is a registered 501(c)(3) non-profit organization.
    Contributions are generally tax-deductible to the fullest extent allowed
    by law. Your emailed receipt serves as your record for tax purposes; no
    goods or services were provided in exchange for your gift.
    Please consult your tax advisor for guidance specific to your situation.
  </p>
  <p class="muted">
    <!-- {{PLACEHOLDER: confirm correct EIN / tax ID for receipts}} -->
    EIN available on request at
    <a href="mailto:info@ndasafoundation.org">info@ndasafoundation.org</a>.
  </p>
</aside>

<section class="other-ways" aria-labelledby="other-ways-heading">
  <h2 id="other-ways-heading">Other ways to give</h2>
  <ul class="other-ways__list">
    <li>
      <strong>Mail a check</strong>
      <span class="muted">
        <!-- {{PLACEHOLDER: real mailing address}} -->
        Payable to "NDASA Foundation", mailed to the address listed at
        <a href="https://ndasafoundation.org" rel="noopener">ndasafoundation.org</a>.
      </span>
    </li>
    <li>
      <strong>Employer matching</strong>
      <span class="muted">
        Many employers match charitable contributions. Ask your HR department
        and you may double your impact.
      </span>
    </li>
    <li>
      <strong>Planned giving &amp; bequests</strong>
      <span class="muted">
        <!-- {{PLACEHOLDER: confirm planned giving contact}} -->
        Contact <a href="mailto:info@ndasafoundation.org">info@ndasafoundation.org</a>
        to discuss legacy gifts and named scholarships.
      </span>
    </li>
  </ul>
</section>

<script nonce="<?= Html::h(defined('NDASA_CSP_NONCE') ? NDASA_CSP_NONCE : '') ?>">
(() => {
  const amount  = document.getElementById('amount');
  const presets = document.querySelectorAll('input[data-preset]');
  const other   = document.querySelector('input[data-preset][value="other"]');
  const fees    = document.querySelectorAll('input[name="cover_fees"]');
  const total   = document.getElementById('total-preview');

  const fmt = (cents) =>
    '$' + (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  const parseAmount = () => {
    const v = parseFloat(amount.value);
    if (!isFinite(v) || v <= 0) return 0;
    return Math.round(v * 100);
  };

  const grossUp = (cents) => Math.ceil((cents + 30) / (1 - 0.029));

  const updateTotal = () => {
    const base = parseAmount();
    if (!total) return;
    if (base === 0) { total.hidden = true; return; }
    const cover = document.querySelector('input[name="cover_fees"]:checked')?.value === 'yes';
    const charged = cover ? grossUp(base) : base;
    total.hidden = false;
    total.textContent = cover
      ? `Your card will be charged ${fmt(charged)} so we receive ${fmt(base)} after fees.`
      : `Your card will be charged ${fmt(charged)}.`;
  };

  // Preset click -> fill amount field.
  presets.forEach((el) => {
    el.addEventListener('change', () => {
      if (el.value === 'other') {
        amount.focus();
      } else {
        amount.value = el.value;
      }
      updateTotal();
    });
  });

  // Typing in amount -> switch radio to "Other".
  amount.addEventListener('input', () => {
    const val = amount.value;
    const match = Array.from(presets).find(
      (p) => p.value !== 'other' && p.value === String(parseInt(val, 10)) && !val.includes('.')
    );
    if (match) {
      match.checked = true;
    } else if (other) {
      other.checked = true;
    }
    updateTotal();
  });

  fees.forEach((el) => el.addEventListener('change', updateTotal));

  updateTotal();
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
