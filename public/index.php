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
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
<?php if ($captchaEnabled): ?><script src="https://js.hcaptcha.com/1/api.js" async defer></script><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer></script>
</head>
<body>
<header class="site-header">
  <a href="index.php" class="site-logo" aria-label="Elektron Net Faucet">
    <img src="assets/logo.svg" alt="Elektron Net" width="36" height="36">
    <span><?= h($title) ?></span>
  </a>
  <div class="lang-switch">
    <?php foreach (I18n::LOCALES as $code => $name): ?>
      <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
    <?php endforeach; ?>
  </div>
</header>

<main class="card">
  <p class="lead"><?= h($message) ?></p>
  <p class="amount">
    <?= he('faucet.per_claim', ['amount' => h($amount)]) ?> &middot;
    <?= he('faucet.total_given', ['amount' => h($totalSent)]) ?>
  </p>

  <form id="claim-form" autocomplete="off">
    <label for="address"><?= he('faucet.your_address') ?></label>
    <input id="address" name="address" type="text" required pattern="^[Bb][Ee]1[A-Za-z0-9]{6,87}$"
           placeholder="be1q&hellip;" maxlength="90" spellcheck="false">

    <?php if ($captchaEnabled): ?>
      <div class="h-captcha" data-sitekey="<?= h($captchaSite) ?>"></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <button type="submit" id="claim-btn"><?= he('faucet.submit') ?></button>
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
      <label><?= he('donate.address_label') ?></label>
      <input type="text" id="donate-addr" readonly value="<?= h($faucetAddr) ?>" onclick="this.select()" title="Click to select">
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
          <input id="donate-amount-report" name="donate_amount" type="number"
                 min="0.00000001" step="any"
                 placeholder="<?= he('donate.amount_ph') ?>" required>
        </div>
      </div>
      <label for="donor-msg"><?= he('donate.msg_label') ?></label>
      <textarea id="donor-msg" name="donor_msg" rows="2" maxlength="500"
                placeholder="<?= he('donate.msg_ph') ?>"></textarea>
      <button type="submit" id="donate-btn"><?= he('donate.report_btn') ?></button>
    </form>
    <div id="donate-result" class="result" hidden></div>
  </div>

  <p class="donate-donors-link"><a href="donors.php"><?= he('donate.donors_link') ?></a></p>
</section>
<?php endif; ?>

<script>
const explorer    = <?= json_encode($explorer, JSON_THROW_ON_ERROR) ?>;
const faucetAddr  = <?= json_encode($faucetAddr, JSON_THROW_ON_ERROR) ?>;
const I18N = {
  solve_captcha: <?= json_encode(__('faucet.solve_captcha'), JSON_THROW_ON_ERROR) ?>,
  success:       <?= json_encode(__('faucet.success'),       JSON_THROW_ON_ERROR) ?>,
  network_error: <?= json_encode(__('faucet.network_error'), JSON_THROW_ON_ERROR) ?>,
  donate_thanks: <?= json_encode(__('donate.thanks'),        JSON_THROW_ON_ERROR) ?>,
};

// ── Helpers ──────────────────────────────────────────────────────────
function setLoading(btn, on) {
  btn.disabled = on;
  btn.dataset.orig = btn.dataset.orig || btn.textContent;
  btn.textContent  = on ? '…' : btn.dataset.orig;
}
function showResult(el, ok, html, isHtml = false) {
  el.hidden    = false;
  el.className = 'result ' + (ok ? 'ok' : 'err');
  if (isHtml) el.innerHTML = html; else el.textContent = html;
}

// ── Claim form ───────────────────────────────────────────────────────
document.getElementById('claim-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('claim-btn');
  const out = document.getElementById('result');
  setLoading(btn, true);
  out.hidden = true;
  const fd = new FormData(e.target);
  if (window.hcaptcha) {
    const tok = hcaptcha.getResponse();
    if (!tok) { setLoading(btn, false); showResult(out, false, I18N.solve_captcha); return; }
    fd.set('h-captcha-response', tok);
  }
  try {
    const res  = await fetch('api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      let link = data.txid;
      if (explorer) link = '<a target="_blank" rel="noopener" href="' + explorer + data.txid + '">' + data.txid + '</a>';
      showResult(out, true, I18N.success + ' ' + link, true);
      e.target.reset();
      if (window.hcaptcha) hcaptcha.reset();
    } else {
      showResult(out, false, data.error || 'Error.');
      if (window.hcaptcha) hcaptcha.reset();
    }
  } catch {
    showResult(out, false, I18N.network_error);
  } finally { setLoading(btn, false); }
});

// ── QR code ──────────────────────────────────────────────────────────
function buildURI() {
  const amt = parseFloat(document.getElementById('donate-amount')?.value || '0');
  let uri = 'elektron:' + faucetAddr;
  if (amt > 0) uri += '?amount=' + amt.toFixed(8);
  return uri;
}
function renderQR() {
  const canvas = document.getElementById('qr-canvas');
  if (!canvas || !faucetAddr || typeof QRCode === 'undefined') return;
  QRCode.toCanvas(canvas, buildURI(),
    { width: 180, margin: 1, color: { dark: '#e7ecf3', light: '#181d24' } },
    () => {});
}
document.addEventListener('DOMContentLoaded', () => {
  renderQR();
  const qrAmt  = document.getElementById('donate-amount');
  const rptAmt = document.getElementById('donate-amount-report');
  if (qrAmt)  qrAmt.addEventListener('input',  () => { renderQR(); if (rptAmt) rptAmt.value = qrAmt.value; });
  if (rptAmt) rptAmt.addEventListener('input',  () => { if (qrAmt) { qrAmt.value = rptAmt.value; renderQR(); } });

  // ── Donate report form ─────────────────────────────────────────────
  document.getElementById('donate-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('donate-btn');
    const out = document.getElementById('donate-result');
    setLoading(btn, true);
    out.hidden = true;
    try {
      const res  = await fetch('api.php', { method: 'POST', body: new FormData(e.target) });
      const data = await res.json();
      if (data.ok) {
        showResult(out, true, I18N.donate_thanks);
        e.target.reset();
        setTimeout(() => { location.href = 'donors.php'; }, 2000);
      } else {
        showResult(out, false, data.error || 'Error.');
        setLoading(btn, false);
      }
    } catch {
      showResult(out, false, I18N.network_error);
      setLoading(btn, false);
    }
  });
});
</script>
</body>
</html>
