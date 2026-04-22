<?php
/**
 * @var string              $csrf     CSRF token value for the hidden input.
 * @var bool                $canceled Did the donor come back from a canceled Stripe session.
 * @var array<string,mixed> $values   Sticky values from a validation re-render (default []).
 * @var ?string             $error    Inline error summary shown above the form (default null).
 */

use NDASA\Http\Csrf;
use NDASA\Payment\FeeCalculator;
use NDASA\Support\Html;

$values  ??= [];
$error   ??= null;
$canceled ??= false;

// Donation bounds from env, converted to dollars so the HTML5 input min/max
// and the JS preview read the same numbers as AmountValidator on the server.
$minCents = (int) ($_ENV['DONATION_MIN_CENTS'] ?? 1000);
$maxCents = (int) ($_ENV['DONATION_MAX_CENTS'] ?? 1_000_000);
$minDollars = number_format($minCents / 100, 2, '.', '');
$maxDollars = number_format($maxCents / 100, 2, '.', '');

// Resolve the preset to select and the amount to prefill. On a fresh render
// the default tier is $100 (anchors the donor toward a considered gift
// rather than starting from an empty free-form field).
$VALID_PRESETS = ['25', '50', '100', '250', '500'];
$submittedPreset = (string) ($values['preset'] ?? '');
$submittedAmount = trim((string) ($values['amount'] ?? ''));

if ($submittedPreset === 'other') {
    $selectedPreset = 'other';
} elseif (in_array($submittedPreset, $VALID_PRESETS, true)) {
    $selectedPreset = $submittedPreset;
} elseif ($submittedAmount !== '' && !in_array($submittedAmount, $VALID_PRESETS, true)) {
    $selectedPreset = 'other';
} else {
    $selectedPreset = '100';
}

if ($submittedAmount !== '') {
    $amountValue = $submittedAmount;
} elseif ($selectedPreset !== 'other') {
    $amountValue = $selectedPreset;
} else {
    $amountValue = '';
}

// Fee cover defaults to "yes" — the foundation nets the donor's intended
// amount and the copy "so 100% of your gift funds our programs" becomes the
// default framing rather than the opt-in alternative. Sticky re-renders still
// honor what the donor actually selected.
$hasCoverFeesSubmission = array_key_exists('cover_fees', $values);
$coverFeesSticky = $hasCoverFeesSubmission
    ? (($values['cover_fees'] ?? '') === 'yes')
    : true;

// Newsletter opt-in default. Pre-checked on a fresh render so we build the
// donor list by default; re-renders respect the donor's last choice.
$hasOptinSubmission = array_key_exists('email_optin', $values);
$emailOptinSticky = $hasOptinSubmission
    ? (($values['email_optin'] ?? '') === 'yes')
    : true;

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

<?php if ($error !== null && $error !== ''): ?>
  <div class="notice notice--error" role="alert">
    <?= Html::h($error) ?>
  </div>
<?php endif; ?>

<section class="impact" aria-labelledby="impact-heading">
  <h2 id="impact-heading">What your gift makes possible</h2>
  <p class="muted impact__hint">Tap a card to pick that amount.</p>
  <ul class="impact-grid">
    <li>
      <button type="button" class="impact-card" data-impact-amount="25"
        aria-label="Choose $25 donation to provide clothing for an orphaned child">
        <div class="impact-card__amount">$25</div>
        <p>
          Clothes an orphaned child for the season — warm essentials so
          they stay in school and keep their dignity.
        </p>
      </button>
    </li>
    <li>
      <button type="button" class="impact-card impact-card--default" data-impact-amount="100"
        aria-label="Choose $100 donation to keep a child in school">
        <div class="impact-card__amount">$100</div>
        <p>
          Keeps a child from a low-income family in school — tuition,
          books, and supplies so lack of money never ends their education.
        </p>
      </button>
    </li>
    <li>
      <button type="button" class="impact-card" data-impact-amount="500"
        aria-label="Choose $500 donation to help bring safe drinking water to a rural village">
        <div class="impact-card__amount">$500</div>
        <p>
          Helps bring a well or filtration system to a rural village so
          families have reliable access to safe drinking water.
        </p>
      </button>
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
      <div class="allocation-bars__track"><div class="allocation-bars__fill allocation-bars__fill--78"></div></div>
    </li>
    <li>
      <div class="allocation-bars__label"><span>Education &amp; outreach</span><strong>17%</strong></div>
      <div class="allocation-bars__track"><div class="allocation-bars__fill allocation-bars__fill--17"></div></div>
    </li>
    <li>
      <div class="allocation-bars__label"><span>Administration</span><strong>5%</strong></div>
      <div class="allocation-bars__track"><div class="allocation-bars__fill allocation-bars__fill--5"></div></div>
    </li>
  </ul>
</section>

<form class="donation-form" method="post" action="<?= Html::h(NDASA_BASE_PATH) ?>/checkout" novalidate>
  <input type="hidden" name="<?= Html::h(Csrf::FIELD) ?>" value="<?= Html::h($csrf) ?>">

  <fieldset class="amount-group">
    <legend>Choose an amount <span class="req" aria-hidden="true">*</span></legend>

    <div class="presets" role="radiogroup" aria-label="Preset donation amounts">
      <?php foreach ([25, 50, 100, 250, 500] as $preset): ?>
        <label class="preset">
          <input type="radio" name="preset" value="<?= $preset ?>" data-preset
            <?= $selectedPreset === (string) $preset ? 'checked' : '' ?>>
          <span>$<?= $preset ?></span>
        </label>
      <?php endforeach; ?>
      <label class="preset preset--other">
        <input type="radio" name="preset" value="other" data-preset
          <?= $selectedPreset === 'other' ? 'checked' : '' ?>>
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
        min="<?= Html::h($minDollars) ?>"
        max="<?= Html::h($maxDollars) ?>"
        step="0.01"
        inputmode="decimal"
        autocomplete="off"
        aria-describedby="amount-help"
        placeholder="Other amount"
        value="<?= Html::h($amountValue) ?>"
      >
    </div>
    <small id="amount-help">
      Minimum $<?= Html::h($minDollars) ?>. Maximum $<?= Html::h(number_format($maxCents / 100, 0, '.', ',')) ?> per transaction.
    </small>
  </fieldset>

  <fieldset>
    <legend>Your details</legend>

    <div class="row">
      <label for="fname">
        First name <span class="req" aria-hidden="true">*</span>
        <input type="text" id="fname" name="fname" maxlength="100" required autocomplete="given-name"
          value="<?= Html::h((string) ($values['fname'] ?? '')) ?>">
      </label>
      <label for="lname">
        Last name <span class="req" aria-hidden="true">*</span>
        <input type="text" id="lname" name="lname" maxlength="100" required autocomplete="family-name"
          value="<?= Html::h((string) ($values['lname'] ?? '')) ?>">
      </label>
    </div>

    <label for="email">
      Email <span class="req" aria-hidden="true">*</span>
      <input type="email" id="email" name="email" maxlength="254" required autocomplete="email" aria-describedby="email-help"
        value="<?= Html::h((string) ($values['email'] ?? '')) ?>">
    </label>
    <small id="email-help">Your receipt will be sent here.</small>

    <label class="inline email-optin">
      <input type="checkbox" name="email_optin" value="yes" <?= $emailOptinSticky ? 'checked' : '' ?>>
      Email me occasional updates about the impact of my gift.
    </label>
  </fieldset>

  <fieldset>
    <legend>Dedicate this gift <span class="muted">(optional)</span></legend>
    <label for="dedication">
      In memory of, in honor of, or on behalf of someone
      <textarea id="dedication" name="dedication" rows="2" maxlength="200"
        autocomplete="off"
        placeholder="e.g. In memory of Jane Doe"><?= Html::h((string) ($values['dedication'] ?? '')) ?></textarea>
    </label>
    <small class="muted">Shown on the staff notification; not printed on the donor receipt.</small>
  </fieldset>

  <fieldset class="fees">
    <legend>Cover the processing fee?</legend>
    <p class="fees__note">
      Card fees take about <span id="fee-delta" class="fees__delta">a little</span>
      out of each donation. If you'd like, add it so 100% of your gift funds
      our programs.
    </p>
    <div class="fees__options">
      <label class="inline">
        <input type="radio" name="cover_fees" value="yes" <?= $coverFeesSticky ? 'checked' : '' ?>>
        Yes, I'll add <span id="fee-delta-yes" class="fees__delta-amount">the fee</span>
      </label>
      <label class="inline">
        <input type="radio" name="cover_fees" value="no" <?= $coverFeesSticky ? '' : 'checked' ?>>
        No thanks
      </label>
    </div>
  </fieldset>

  <p id="total-preview" class="total-preview" aria-live="polite"></p>

  <button type="submit" id="donation-submit" class="btn btn--primary"
    data-label-ready="Donate securely &rarr;"
    data-label-empty="Choose an amount above"
    data-label-busy="Redirecting to secure checkout&hellip;">
    Donate securely &rarr;
  </button>

  <p class="give-monthly">
    <a href="mailto:info@ndasafoundation.org?subject=I%27d%20like%20to%20give%20monthly&amp;body=Hi%20NDASA%20Foundation%2C%0A%0AI%27d%20like%20to%20set%20up%20a%20recurring%20monthly%20donation.%20Please%20get%20in%20touch%20with%20details.%0A%0AThank%20you.">
      Prefer to give monthly? Let us know &rarr;
    </a>
  </p>

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
        Payable to &ldquo;NDASA Foundation.&rdquo; Email
        <a href="mailto:info@ndasafoundation.org">info@ndasafoundation.org</a>
        for the current mailing address.
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
  // Config exposed from PHP so fee math cannot drift between server and
  // client. Values are literal numbers, not strings — safe to interpolate
  // inside a nonced script tag.
  const CFG = {
    feePercent:    <?= FeeCalculator::PERCENT ?>,
    feeFixedCents: <?= FeeCalculator::FIXED_CENTS ?>,
    minCents:      <?= (int) $minCents ?>,
    maxCents:      <?= (int) $maxCents ?>,
  };

  const form     = document.querySelector('.donation-form');
  const amount   = document.getElementById('amount');
  const presets  = document.querySelectorAll('input[data-preset]');
  const other    = document.querySelector('input[data-preset][value="other"]');
  const fees     = document.querySelectorAll('input[name="cover_fees"]');
  const total    = document.getElementById('total-preview');
  const feeSpan  = document.getElementById('fee-delta');
  const feeYes   = document.getElementById('fee-delta-yes');
  const submit   = document.getElementById('donation-submit');

  const fmt = (cents) =>
    '$' + (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  const parseAmount = () => {
    const v = parseFloat(amount.value);
    if (!isFinite(v) || v <= 0) return 0;
    const cents = Math.round(v * 100);
    if (cents < CFG.minCents || cents > CFG.maxCents) return 0;
    return cents;
  };

  const grossUp = (cents) =>
    Math.ceil((cents + CFG.feeFixedCents) / (1 - CFG.feePercent));

  const updateAll = () => {
    const base = parseAmount();
    const cover = document.querySelector('input[name="cover_fees"]:checked')?.value === 'yes';
    const delta = base > 0 ? grossUp(base) - base : 0;

    // Fee copy: live dollar amount. When base is 0 we can't compute the
    // per-donation delta; show a stable approximation ("about $3.50 on $100").
    if (feeSpan) {
      feeSpan.textContent = base > 0 ? fmt(delta) : fmt(grossUp(10000) - 10000);
    }
    if (feeYes) {
      feeYes.textContent = base > 0 ? fmt(delta) : 'the fee';
    }

    // Total preview: confident dollar figure or a stable zero-state.
    if (total) {
      if (base === 0) {
        total.textContent = 'Enter an amount above to see what your card will be charged.';
      } else {
        const charged = cover ? grossUp(base) : base;
        total.textContent = cover
          ? `Your card will be charged ${fmt(charged)} so we receive ${fmt(base)} after fees.`
          : `Your card will be charged ${fmt(charged)}.`;
      }
    }

    // Dynamic CTA. Shows the donor's *intended* amount (not grossed-up),
    // which is the number they care about.
    if (submit && !submit.disabled) {
      submit.innerHTML = base === 0
        ? submit.dataset.labelEmpty
        : `Donate ${fmt(base)} securely &rarr;`;
    }
  };

  // Preset click -> fill amount field.
  presets.forEach((el) => {
    el.addEventListener('change', () => {
      if (el.value === 'other') {
        amount.focus();
      } else {
        amount.value = el.value;
      }
      updateAll();
    });
  });

  // Impact card click -> select matching preset, scroll form into view,
  // update the total preview. Preserves the emotional commitment moment so
  // the donor doesn't have to re-pick the amount they just decided on.
  document.querySelectorAll('[data-impact-amount]').forEach((card) => {
    card.addEventListener('click', () => {
      const v = card.getAttribute('data-impact-amount');
      const match = Array.from(presets).find((p) => p.value === v);
      if (match) match.checked = true;
      amount.value = v;
      updateAll();
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // Typing in amount -> switch radio to "Other" when the value doesn't
  // match a whole-dollar preset.
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
    updateAll();
  });

  fees.forEach((el) => el.addEventListener('change', updateAll));

  // Submit feedback: disable the button, swap label, show spinner.
  // Network latency between /checkout and Stripe Checkout is 300–1500ms on
  // mobile; this removes the "did I click it?" gap and double-click races.
  if (form && submit) {
    form.addEventListener('submit', () => {
      submit.disabled = true;
      submit.innerHTML =
        '<span class="spinner" aria-hidden="true"></span> ' + submit.dataset.labelBusy;
    });
  }

  // bfcache restore (back-button from Stripe) — re-enable so the donor
  // can try again without reloading.
  window.addEventListener('pageshow', (e) => {
    if (!submit) return;
    if (e.persisted) {
      submit.disabled = false;
      updateAll();
    }
  });

  updateAll();
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
