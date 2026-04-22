<?php
/**
 * Admin dashboard — shell only. Metrics are placeholders.
 */
$title  = 'Dashboard';
$active = 'dashboard';

ob_start();
?>
<h1>NDASA Dashboard</h1>

<div class="stats">
  <div class="stat">
    <div class="stat__label">Total Donations</div>
    <div class="stat__value">&mdash;</div>
  </div>
  <div class="stat">
    <div class="stat__label">Total Donors</div>
    <div class="stat__value">&mdash;</div>
  </div>
  <div class="stat">
    <div class="stat__label">Page Views</div>
    <div class="stat__value">&mdash;</div>
  </div>
  <div class="stat">
    <div class="stat__label">Conversion Rate</div>
    <div class="stat__value">&mdash;</div>
  </div>
</div>

<h2>Recent Donations</h2>
<div class="panel">
  <table>
    <thead>
      <tr><th>Date</th><th>Donor</th><th>Amount</th><th>Status</th></tr>
    </thead>
    <tbody>
      <tr class="empty"><td colspan="4">No donations to display yet.</td></tr>
    </tbody>
  </table>
</div>

<p class="muted" style="margin-top: 24px;">
  Metrics and recent-donation data are not implemented in this release. The
  Stripe dashboard remains the authoritative record for donation activity.
</p>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
