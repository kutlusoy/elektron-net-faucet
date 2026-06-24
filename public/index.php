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

if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    I18n::setLocale($_GET['lang']);
}

$s = Db::getAllSettings();
$title = $s['faucet_title'] ?? __('faucet.title');
$message = $s['faucet_message'] ?? __('faucet.lead');
$amount = $s['amount_elek'] ?? '0';
$captchaSite = Captcha::siteKey();
$captchaEnabled = Captcha::isEnabled();
$csrf = Csrf::token();
$explorer = $s['explorer_url'] ?? '';
$totalSent = RateLimiter::satToElek(Stats::totalSentSat());
$locale = I18n::locale();

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="<?= h($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php if ($captchaEnabled): ?><script src="https://js.hcaptcha.com/1/api.js" async defer></script><?php endif; ?>
</head>
<body>
<main class="card">
  <div class="lang-switch">
    <?php foreach (I18n::LOCALES as $code => $name): ?>
      <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
    <?php endforeach; ?>
  </div>

  <h1><?= h($title) ?></h1>
  <p class="lead"><?= h($message) ?></p>
  <p class="amount">
    <?= he('faucet.per_claim', ['amount' => h($amount)]) ?> ·
    <?= he('faucet.total_given', ['amount' => h($totalSent)]) ?>
  </p>

  <form id="claim-form" autocomplete="off">
    <label for="address"><?= he('faucet.your_address') ?></label>
    <input id="address" name="address" type="text" required pattern="^[Bb][Ee]1[A-Za-z0-9]{6,87}$"
           placeholder="be1q…" maxlength="90" spellcheck="false">

    <?php if ($captchaEnabled): ?>
      <div class="h-captcha" data-sitekey="<?= h($captchaSite) ?>"></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <button type="submit"><?= he('faucet.submit') ?></button>
  </form>

  <div id="result" class="result" hidden></div>

  <footer>
    <a href="admin.php"><?= he('faucet.admin_link') ?></a>
  </footer>
</main>

<script>
const explorer = <?= json_encode($explorer, JSON_THROW_ON_ERROR) ?>;
const I18N = {
  solve_captcha: <?= json_encode(__('faucet.solve_captcha'), JSON_THROW_ON_ERROR) ?>,
  success: <?= json_encode(__('faucet.success'), JSON_THROW_ON_ERROR) ?>,
  network_error: <?= json_encode(__('faucet.network_error'), JSON_THROW_ON_ERROR) ?>,
};
document.getElementById('claim-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button');
  const out = document.getElementById('result');
  btn.disabled = true;
  out.hidden = true; out.className = 'result';
  const fd = new FormData(e.target);
  if (window.hcaptcha) {
    const tok = hcaptcha.getResponse();
    if (!tok) { btn.disabled = false; out.hidden = false; out.classList.add('err'); out.textContent = I18N.solve_captcha; return; }
    fd.set('h-captcha-response', tok);
  }
  try {
    const res = await fetch('api.php', { method: 'POST', body: fd });
    const data = await res.json();
    out.hidden = false;
    if (data.ok) {
      out.classList.add('ok');
      let link = data.txid;
      if (explorer) link = '<a target="_blank" rel="noopener" href="' + explorer + data.txid + '">' + data.txid + '</a>';
      out.innerHTML = I18N.success + ' ' + link;
      e.target.reset();
      if (window.hcaptcha) hcaptcha.reset();
    } else {
      out.classList.add('err');
      out.textContent = data.error || 'Error.';
      if (window.hcaptcha) hcaptcha.reset();
    }
  } catch (err) {
    out.hidden = false; out.classList.add('err');
    out.textContent = I18N.network_error;
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
