<?php
/**
 * Shared paginator row. Included by transactions, subscriptions, donors
 * index pages. Preserves all query-string filters by re-emitting them
 * into the prev/next links.
 *
 * @var int $total    Total matching rows.
 * @var int $page     Current 1-based page.
 * @var int $perPage  Rows per page (25/50/100/500).
 * @var string $basePath e.g. /donation/admin/transactions
 */

use NDASA\Support\Html;

$total   = (int) ($total   ?? 0);
$page    = (int) ($page    ?? 1);
$perPage = (int) ($perPage ?? 25);
$pages   = max(1, (int) ceil($total / max(1, $perPage)));

// Preserve every filter the caller passed in, minus page/per_page which we
// own. http_build_query keeps urlencoding consistent with PHP's parser.
$preserved = $_GET;
unset($preserved['page'], $preserved['per_page']);
$qs = static function (array $extra) use ($preserved): string {
    $merged = array_merge($preserved, $extra);
    return http_build_query($merged);
};

$prevPage = max(1, $page - 1);
$nextPage = min($pages, $page + 1);

$fromRow = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$toRow   = min($total, $page * $perPage);
?>
<div class="pager">
  <div class="pager__summary">
    <?php if ($total === 0): ?>
      No results.
    <?php else: ?>
      Showing <?= Html::h((string) $fromRow) ?>&ndash;<?= Html::h((string) $toRow) ?>
      of <?= Html::h((string) $total) ?>
      &middot;
      <label>
        Per page
        <select onchange="location.href = '<?= Html::h($basePath) ?>?<?= Html::h($qs(['per_page' => '__SIZE__'])) ?>'.replace('__SIZE__', this.value)">
          <?php foreach ([25, 50, 100, 500] as $s): ?>
            <option value="<?= $s ?>" <?= $perPage === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
  </div>
  <div class="pager__links">
    <?php if ($page > 1): ?>
      <a href="<?= Html::h($basePath) ?>?<?= Html::h($qs(['page' => $prevPage, 'per_page' => $perPage])) ?>">&larr; Prev</a>
    <?php else: ?>
      <span class="disabled">&larr; Prev</span>
    <?php endif; ?>
    <span>Page <?= Html::h((string) $page) ?> of <?= Html::h((string) $pages) ?></span>
    <?php if ($page < $pages): ?>
      <a href="<?= Html::h($basePath) ?>?<?= Html::h($qs(['page' => $nextPage, 'per_page' => $perPage])) ?>">Next &rarr;</a>
    <?php else: ?>
      <span class="disabled">Next &rarr;</span>
    <?php endif; ?>
  </div>
</div>
