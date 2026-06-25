<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
\ElektronFaucet\Bootstrap::init();

use ElektronFaucet\Db;
use ElektronFaucet\I18n;

if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    I18n::setLocale($_GET['lang']);
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$locale    = I18n::locale();
$s         = Db::getAllSettings();
$title     = $s['faucet_title'] ?? __('faucet.title');

$donations = Db::fetchAll(
    'SELECT id, amount_elek, donor_name, message, created_at FROM donations ORDER BY created_at DESC LIMIT 500'
);
$agg = Db::fetchOne('SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_elek),0) AS total FROM donations');
$totalAmount = number_format((float)($agg['total'] ?? 0), 4);
$totalCount  = (int)($agg['cnt'] ?? 0);
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
<header class="site-header">
  <a href="index.php" class="site-logo" aria-label="<?= h($title) ?>">
    <img src="assets/logo.svg" alt="Elektron Net" width="36" height="36">
    <span><?= h($title) ?></span>
  </a>
  <div class="lang-switch">
    <?php foreach (I18n::LOCALES as $code => $name): ?>
      <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
    <?php endforeach; ?>
  </div>
</header>

<main class="card donors-card">
  <h1><?= he('donors.title') ?></h1>
  <p class="lead"><?= he('donors.lead') ?></p>

  <?php if ($totalCount > 0): ?>
  <p class="donors-total"><?= he('donors.total', ['amount' => h($totalAmount), 'count' => $totalCount]) ?></p>
  <?php endif; ?>

  <?php if (empty($donations)): ?>
    <p class="muted"><?= he('donors.none') ?></p>
  <?php else: ?>
  <div class="donors-table-wrap">
    <table class="donors-table">
      <thead>
        <tr>
          <th><?= he('donors.col.date') ?></th>
          <th><?= he('donors.col.name') ?></th>
          <th><?= he('donors.col.message') ?></th>
          <th><?= he('donors.col.amount') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($donations as $d): ?>
        <tr>
          <td class="donors-date"><?= h(substr((string)$d['created_at'], 0, 10)) ?></td>
          <td class="donors-name"><?= h((string)($d['donor_name'] ?: he('donors.anonymous'))) ?></td>
          <td class="donors-msg"><?= h((string)($d['message'] ?? '')) ?></td>
          <td class="donors-amount"><?= h(rtrim(rtrim(number_format((float)$d['amount_elek'], 8), '0'), '.')) ?> ELEK</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <footer>
    <a href="index.php"><?= he('donors.back') ?></a>
  </footer>
</main>
</body>
</html>
