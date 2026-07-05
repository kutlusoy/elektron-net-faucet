<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
\ElektronFaucet\Bootstrap::init();

use ElektronFaucet\Db;
use ElektronFaucet\I18n;
use ElektronFaucet\Stats;
use ElektronFaucet\Wallet;

if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    I18n::setLocale($_GET['lang']);
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$locale     = I18n::locale();
$s          = Db::getAllSettings();
$title      = $s['faucet_title'] ?? __('faucet.title');
$faucetAddr = trim((string)($s['sender_addr'] ?? ''));
$explorer   = $s['explorer_url'] ?? '';

$txList        = [];
$rpcErr        = null;
$totalReceived = 0.0;

$perPage    = 10;
$maxEntries = 100;

if ($faucetAddr !== '') {
    try {
        $rpc    = Wallet::fromSettings()->rpc();
        $txList = array_slice(Stats::donationsTo($rpc, $faucetAddr), 0, $maxEntries);
        foreach ($txList as $tx) {
            $totalReceived += (float)($tx['amount'] ?? 0);
        }
    } catch (\Throwable $e) {
        $rpcErr = $e->getMessage();
    }
}

$totalPages = max(1, (int)ceil(count($txList) / $perPage));
$page       = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$pageItems  = array_slice($txList, ($page - 1) * $perPage, $perPage);

function fmtDate(int $ts): string { return date('Y-m-d H:i', $ts); }
?>
<!doctype html>
<html lang="<?= h($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= he('donors.title') ?> &mdash; <?= h($title) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
</head>
<body>
<div class="page wide">

<header class="page-header">
  <a href="admin.php" class="header-admin-link"><?= he('faucet.admin_link') ?></a>
  <a href="index.php" class="site-logo" aria-label="<?= h($title) ?>">
    <img src="assets/logo.svg" alt="" width="64" height="64">
  </a>
  <h1 class="site-name"><?= h($title) ?></h1>
  <div class="header-nav">
    <div class="lang-switch">
      <?php foreach (I18n::LOCALES as $code => $name): ?>
        <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<main class="card">
  <h2><?= he('donors.title') ?></h2>
  <p class="lead"><?= he('donors.lead') ?></p>

  <?php if ($faucetAddr === ''): ?>
    <p class="muted"><?= he('donors.no_addr') ?></p>

  <?php elseif ($rpcErr !== null): ?>
    <div class="result err"><?= h(__('donors.rpc_error') . ' ' . $rpcErr) ?></div>

  <?php elseif (empty($txList)): ?>
    <p class="muted"><?= he('donors.none') ?></p>

  <?php else: ?>
    <p class="donors-total">
      <?= he('donors.total_received', [
          'amount' => number_format($totalReceived, 4),
          'count'  => count($txList),
      ]) ?>
    </p>
    <div class="donors-table-wrap">
      <table class="donors-table">
        <thead><tr>
          <th><?= he('donors.col.date') ?></th>
          <th><?= he('donors.col.confirmations') ?></th>
          <th><?= he('donors.col.txid') ?></th>
          <th><?= he('donors.col.amount') ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($pageItems as $tx): ?>
            <?php
              $confs = (int)($tx['confirmations'] ?? 0);
              $txid  = (string)($tx['txid'] ?? '');
              $amt   = (float)($tx['amount'] ?? 0);
              $time  = (int)($tx['time'] ?? $tx['timereceived'] ?? 0);
            ?>
            <tr>
              <td class="donors-date"><?= h($time ? fmtDate($time) : '—') ?></td>
              <td class="donors-conf">
                <span class="<?= $confs >= 6 ? 'conf-ok' : 'conf-pending' ?>">
                  <?= $confs >= 6 ? '✓' : ($confs . ' conf') ?>
                </span>
              </td>
              <td class="donors-tx mono">
                <?php if ($txid !== '' && $explorer !== ''): ?>
                  <a href="<?= h($explorer . $txid) ?>" target="_blank" rel="noopener"
                     title="<?= h($txid) ?>"><?= h(substr($txid, 0, 16)) ?>&hellip;</a>
                <?php elseif ($txid !== ''): ?>
                  <span title="<?= h($txid) ?>"><?= h(substr($txid, 0, 16)) ?>&hellip;</span>
                <?php endif; ?>
              </td>
              <td class="donors-amount"><?= h(rtrim(rtrim(number_format($amt, 8), '0'), '.')) ?> ELEK</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1):
      $langQS  = isset($_GET['lang']) ? '&lang=' . rawurlencode((string)$_GET['lang']) : '';
      $prevUrl = '?page=' . ($page - 1) . $langQS;
      $nextUrl = '?page=' . ($page + 1) . $langQS;
    ?>
      <nav class="pagination">
        <?php if ($page > 1): ?>
          <a class="pagination-link" href="<?= h($prevUrl) ?>">&larr; <?= he('donors.pagination.prev') ?></a>
        <?php else: ?>
          <span class="pagination-link disabled">&larr; <?= he('donors.pagination.prev') ?></span>
        <?php endif; ?>

        <span class="pagination-info"><?= he('donors.pagination.page_info', ['page' => (string)$page, 'pages' => (string)$totalPages]) ?></span>

        <?php if ($page < $totalPages): ?>
          <a class="pagination-link" href="<?= h($nextUrl) ?>"><?= he('donors.pagination.next') ?> &rarr;</a>
        <?php else: ?>
          <span class="pagination-link disabled"><?= he('donors.pagination.next') ?> &rarr;</span>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <footer>
    <a href="index.php"><?= he('donors.back') ?></a>
  </footer>
</main>

<footer class="site-footer">
  <?= he('footer.presented_by') ?>
  <a href="https://elektron-net.org" target="_blank" rel="noopener">https://elektron-net.org</a>
</footer>

</div>
</body>
</html>
