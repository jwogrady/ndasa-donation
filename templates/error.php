<?php
/** @var string $error */

use NDASA\Support\Html;

$title = 'We hit a problem — NDASA Foundation';

$code = http_response_code();
$heading = match (true) {
    $code === 429                  => 'Too many requests',
    $code === 404                  => 'Page not found',
    $code === 400 || $code === 422 => 'We couldn\'t process that',
    $code === 502                  => 'Payment processor is busy',
    default                        => 'Something went wrong',
};

// Context-specific recovery guidance shown below the error message.
$guidance = match (true) {
    $code === 429 => [
        'title' => 'Why this happens',
        'items' => [
            'Too many donation attempts came from your network in a short window.',
            'This usually clears within a minute or two — please wait and try again.',
            'If you were not the source of multiple attempts and this keeps happening, contact us below.',
        ],
    ],
    $code === 400 || $code === 422 => [
        'title' => 'A few things to check',
        'items' => [
            'Your name, email address, and a donation amount of at least $10.00 are all required.',
            'If you were on the page a long time before submitting, refresh and try again — your session may have expired.',
            'Use numbers only for the amount (for example, 50 or 50.00 — not "$50" or "fifty").',
        ],
    ],
    $code === 502 => [
        'title' => 'This is on our end',
        'items' => [
            'We briefly couldn\'t reach our payment processor. No charge was made.',
            'Waiting a minute and trying again almost always resolves it.',
            'If this keeps happening, contact us below and we\'ll help complete your donation another way.',
        ],
    ],
    $code === 404 => [
        'title' => 'Getting back on track',
        'items' => [
            'The page you were looking for may have moved.',
            'The donation form is always at the link below.',
        ],
    ],
    default => [
        'title' => 'What to try',
        'items' => [
            'Refresh the page and try again.',
            'If the problem persists, contact us below — we\'ll help you complete your donation.',
            'No charge is ever made unless you complete payment on Stripe\'s secure checkout page.',
        ],
    ],
};

ob_start();
?>
<section class="status status--error">
  <svg class="status__icon" aria-hidden="true" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <div>
    <h1><?= Html::h($heading) ?></h1>
    <p class="lede"><?= Html::h($error) ?></p>
  </div>
</section>

<section class="recovery" aria-labelledby="recovery-heading">
  <h2 id="recovery-heading"><?= Html::h($guidance['title']) ?></h2>
  <ul class="recovery__list">
    <?php foreach ($guidance['items'] as $item): ?>
      <li><?= Html::h($item) ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<div class="reassure" role="note">
  <svg class="reassure__icon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  <p>
    <strong>Your card has not been charged.</strong>
    Payments only go through on Stripe's secure checkout page after you confirm
    the amount. If you see a pending charge that never settles, it will drop off
    your statement automatically.
  </p>
</div>

<p class="actions">
  <a class="btn btn--primary" href="<?= Html::h(NDASA_BASE_PATH) ?>/">Return to the donation form</a>
</p>

<aside class="contact-help" aria-label="Contact for help">
  <h2>Need a hand?</h2>
  <p>
    We'd rather hear from you than lose your gift. Email
    <a href="mailto:info@ndasafoundation.org">info@ndasafoundation.org</a>
    with a brief description of what happened and the approximate time, and
    we'll follow up personally to help complete your donation.
  </p>
  <p class="muted">
    Prefer to donate by mail? Make checks payable to
    <strong>"NDASA Foundation"</strong> — details on the donation page.
  </p>
</aside>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
