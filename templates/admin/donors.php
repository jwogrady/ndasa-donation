<?php
/**
 * Donors index.
 *
 * @var list<array{email:string,contact_name:?string,donations:int,total_cents:int,first_at:int,last_at:int}> $rows
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $stripeMode
 */

use NDASA\Support\Html;

$title  = 'Donors';
$active = 'donors';

$fmtMoney = static fn (int $c): string => '$' . number_format($c / 100, 2);
$basePath = Html::h(NDASA_BASE_PATH) . '/admin/donors';

ob_start();
?>
<h1>Donors</h1>
<p class="muted mode-filter-note">
  Showing <strong><?= $stripeMode === 'test' ? 'TEST' : 'LIVE' ?></strong> donors only.
  One row per unique email, ordered by lifetime giving.
</p>

<?php require __DIR__ . '/_pager.php'; ?>

<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Donor</th>
        <th>Email</th>
        <th>Gifts</th>
        <th>Lifetime</th>
        <th>First Gift</th>
        <th>Last Gift</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows === []): ?>
        <tr class="empty"><td colspan="6">No donors yet.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $d):
          // SHA-256 the email for the URL so donor identifiers don't land
          // in server access logs or browser history. The page still shows
          // the email inline — it's behind admin auth.
          $hash = hash('sha256', $d['email']);
          $detailUrl = Html::h(NDASA_BASE_PATH) . '/admin/donors/' . $hash;
          $label = $d['contact_name'] !== null && $d['contact_name'] !== ''
              ? $d['contact_name']
              : $d['email'];
        ?>
          <tr>
            <td><a href="<?= $detailUrl ?>"><?= Html::h($label) ?></a></td>
            <td><?= Html::h($d['email']) ?></td>
            <td><?= Html::h((string) $d['donations']) ?></td>
            <td><?= Html::h($fmtMoney($d['total_cents'])) ?></td>
            <td><?= Html::h(date('Y-m-d', $d['first_at'])) ?></td>
            <td><?= Html::h(date('Y-m-d', $d['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/_pager.php'; ?>

<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
