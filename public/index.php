<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
\ElektronFaucet\Bootstrap::init();

use ElektronFaucet\Db;
use ElektronFaucet\Captcha;
use ElektronFaucet\Csrf;
use ElektronFaucet\Stats;
use ElektronFaucet\RateLimiter;
use ElektronFaucet\I18n;
use ElektronFaucet\Flash;

if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    I18n::setLocale($_GET['lang']);
}

$s           = Db::getAllSettings();
$title       = $s['faucet_title'] ?? __('faucet.title');
$message     = $s['faucet_message'] ?? __('faucet.lead');
$amount      = $s['amount_elek'] ?? '0';
$captchaSite = Captcha::siteKey();
$captchaOn   = Captcha::isEnabled();
$csrf        = Csrf::token();
$explorer    = $s['explorer_url'] ?? '';
$totalSent   = RateLimiter::satToElek(Stats::totalSentSat());
$locale      = I18n::locale();
$faucetAddr  = trim((string)($s['sender_addr'] ?? ''));

// The flash payload may carry the txid behind a NUL separator so we can
// render an explorer link on success without leaking it into the URL bar.
$flash    = Flash::take();
$flashTx  = null;
$flashMsg = null;
if ($flash !== null) {
    $parts    = explode("\0", $flash['msg'], 2);
    $flashMsg = $parts[0];
    if ($flash['ok'] && isset($parts[1]) && $parts[1] !== '') {
        $flashTx = $parts[1];
    }
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="<?= h($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
<?php if ($captchaOn): ?><script src="https://js.hcaptcha.com/1/api.js" async defer></script><?php endif; ?>
</head>
<body>
<div class="page">

<header class="page-header">
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
  <p class="lead"><?= h($message) ?></p>
  <p class="amount">
    <?= he('faucet.per_claim', ['amount' => h($amount)]) ?> &middot;
    <?= he('faucet.total_given', ['amount' => h($totalSent)]) ?>
  </p>

  <?php if ($flash !== null): ?>
    <div class="result <?= $flash['ok'] ? 'ok' : 'err' ?>">
      <?= h((string)$flashMsg) ?>
      <?php if ($flashTx !== null && $explorer !== ''): ?>
        <a target="_blank" rel="noopener" href="<?= h($explorer . $flashTx) ?>"><?= h($flashTx) ?></a>
      <?php elseif ($flashTx !== null): ?>
        <?= h($flashTx) ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="api.php" autocomplete="off">
    <label for="address"><?= he('faucet.your_address') ?></label>
    <input id="address" name="address" type="text" required
           pattern="^[Bb][Ee]1[A-Za-z0-9]{6,87}$"
           placeholder="be1q&hellip;" maxlength="90" spellcheck="false">

    <?php if ($captchaOn): ?>
      <div class="h-captcha" data-sitekey="<?= h($captchaSite) ?>"></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="form-actions"><button type="submit"><?= he('faucet.submit') ?></button></div>
  </form>

  <footer>
    <a href="admin.php"><?= he('faucet.admin_link') ?></a>
  </footer>
</main>

<?php if ($faucetAddr !== ''):
  // Server-rendered donation block. No live URI builder anymore; the user
  // copies the static URI or types the amount into their wallet directly.
?>
<section class="card donate-section">
  <h2><?= he('donate.title') ?></h2>
  <p class="lead"><?= he('donate.lead') ?></p>

  <p class="donate-hint"><?= he('donate.uri_hint') ?></p>

  <div class="uri-box">
    <span class="uri-box-content">elek:<?= h($faucetAddr) ?>?label=Faucet%20Donation</span>
  </div>

  <div class="pay-info">
    <div class="pi-row">
      <span class="pi-label"><?= he('donate.pi_address') ?></span>
      <span class="pi-value mono"><?= h($faucetAddr) ?></span>
    </div>
    <div class="pi-row">
      <span class="pi-label"><?= he('donate.pi_memo') ?></span>
      <span class="pi-value">Faucet Donation</span>
    </div>
  </div>

  <p class="donate-donors-link"><a href="donors.php"><?= he('donate.donors_link') ?></a></p>
</section>
<?php endif; ?>

</div>
</body>
</html>
