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

$s            = Db::getAllSettings();
$title        = $s['faucet_title'] ?? __('faucet.title');
$message      = $s['faucet_message'] ?? __('faucet.lead');
$amount       = $s['amount_elek'] ?? '0';
$captchaSite  = Captcha::siteKey();
$captchaEnabled = Captcha::isEnabled();
$csrf         = Csrf::token();
$explorer     = $s['explorer_url'] ?? '';
$totalSent    = RateLimiter::satToElek(Stats::totalSentSat());
$locale       = I18n::locale();
$faucetAddr   = trim((string)($s['sender_addr'] ?? ''));

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="<?= h($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php if ($captchaEnabled): ?><script src="https://js.hcaptcha.com/1/api.js" async defer></script><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
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
    <?= he('faucet.per_claim', ['amount' => h($amount)]) ?> &middot;
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
    &middot;
    <a href="donors.php"><?= he('donors.back_link') ?></a>
  </footer>
</main>

<?php if ($faucetAddr !== ''): ?>
<section class="card donate-section">
  <h2><?= he('donate.title') ?></h2>
  <p class="lead"><?= he('donate.lead') ?></p>

  <div class="donate-layout">
    <div class="qr-wrap">
      <canvas id="qr-canvas"></canvas>
      <p class="qr-hint"><?= he('donate.scan_hint') ?></p>
    </div>
    <div class="donate-fields">
      <label for="donate-addr"><?= he('donate.address_label') ?></label>
      <input id="donate-addr" type="text" readonly value="<?= h($faucetAddr) ?>" onclick="this.select()">

      <label for="donate-amount"><?= he('donate.amount_label') ?></label>
      <input id="donate-amount" type="number" min="0.00000001" step="any"
             placeholder="<?= he('donate.amount_ph') ?>" value="1">
    </div>
  </div>

  <div class="donate-report-wrap">
    <p class="donate-report-intro"><?= he('donate.report_intro') ?></p>
    <form id="donate-form" autocomplete="off">
      <input type="hidden" name="action" value="donate">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="donate-form-row">
        <div>
          <label for="donor-name"><?= he('donate.name_label') ?></label>
          <input id="donor-name" name="donor_name" type="text" maxlength="100"
                 placeholder="<?= he('donate.name_ph') ?>">
        </div>
        <div>
          <label for="donate-amount-report"><?= he('donate.amount_label') ?></label>
          <input id="donate-amount-report" name="donate_amount" type="number" min="0.00000001" step="any"
                 placeholder="<?= he('donate.amount_ph') ?>" required>
        </div>
      </div>
      <label for="donor-msg"><?= he('donate.msg_label') ?></label>
      <textarea id="donor-msg" name="donor_msg" rows="2" maxlength="500"
                placeholder="<?= he('donate.msg_ph') ?>"></textarea>

      <button type="submit"><?= he('donate.report_btn') ?></button>
    </form>
    <div id="donate-result" class="result" hidden></div>
  </div>

  <p class="donate-donors-link">
    <a href="donors.php"><?= he('donate.donors_link') ?></a>
  </p>
</section>
<?php endif; ?>

<script>
const explorer = <?= json_encode($explorer, JSON_THROW_ON_ERROR) ?>;
const faucetAddr = <?= json_encode($faucetAddr, JSON_THROW_ON_ERROR) ?>;
const I18N = {
  solve_captcha: <?= json_encode(__('faucet.solve_captcha'), JSON_THROW_ON_ERROR) ?>,
  success: <?= json_encode(__('faucet.success'), JSON_THROW_ON_ERROR) ?>,
  network_error: <?= json_encode(__('faucet.network_error'), JSON_THROW_ON_ERROR) ?>,
  donate_thanks: <?= json_encode(__('donate.thanks'), JSON_THROW_ON_ERROR) ?>,
};

// Claim form
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
    const res  = await fetch('api.php', { method: 'POST', body: fd });
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
  } catch {
    out.hidden = false; out.classList.add('err');
    out.textContent = I18N.network_error;
  } finally {
    btn.disabled = false;
  }
});

// QR code generation
function updateQR() {
  const canvas = document.getElementById('qr-canvas');
  if (!canvas || !faucetAddr) return;
  const amt = parseFloat(document.getElementById('donate-amount')?.value || '0');
  let uri = 'elektron:' + faucetAddr;
  if (amt > 0) uri += '?amount=' + amt.toFixed(8);
  QRCode.toCanvas(canvas, uri, { width: 180, margin: 1, color: { dark: '#e7ecf3', light: '#181d24' } }, function() {});
}

if (faucetAddr && typeof QRCode !== 'undefined') {
  updateQR();
  const amtInput = document.getElementById('donate-amount');
  if (amtInput) amtInput.addEventListener('input', updateQR);
} else {
  // Wait for QRCode to load
  document.querySelector('script[src*="qrcode"]')?.addEventListener('load', () => {
    updateQR();
    const amtInput = document.getElementById('donate-amount');
    if (amtInput) amtInput.addEventListener('input', updateQR);
  });
}

// Sync donate-amount fields
const qrAmt  = document.getElementById('donate-amount');
const rptAmt = document.getElementById('donate-amount-report');
if (qrAmt && rptAmt) {
  qrAmt.addEventListener('input', () => { rptAmt.value = qrAmt.value; });
  rptAmt.addEventListener('input', () => { qrAmt.value = rptAmt.value; updateQR(); });
}

// Donate report form
const donateForm = document.getElementById('donate-form');
if (donateForm) {
  donateForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    const out = document.getElementById('donate-result');
    btn.disabled = true;
    out.hidden = true; out.className = 'result';
    try {
      const res  = await fetch('api.php', { method: 'POST', body: new FormData(e.target) });
      const data = await res.json();
      out.hidden = false;
      if (data.ok) {
        out.classList.add('ok');
        out.textContent = I18N.donate_thanks;
        e.target.reset();
        setTimeout(() => { window.location.href = 'donors.php'; }, 2000);
      } else {
        out.classList.add('err');
        out.textContent = data.error || 'Error.';
        btn.disabled = false;
      }
    } catch {
      out.hidden = false; out.classList.add('err');
      out.textContent = I18N.network_error;
      btn.disabled = false;
    }
  });
}
</script>
</body>
</html>
