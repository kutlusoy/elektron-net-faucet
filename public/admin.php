<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
\ElektronFaucet\Bootstrap::init();

use ElektronFaucet\Db;
use ElektronFaucet\Auth;
use ElektronFaucet\Csrf;
use ElektronFaucet\Crypto;
use ElektronFaucet\Stats;
use ElektronFaucet\RateLimiter;
use ElektronFaucet\Wallet;
use ElektronFaucet\Logger;
use ElektronFaucet\I18n;

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    I18n::setLocale($_GET['lang']);
}

Auth::gcSessions();

$ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'logout') { Auth::logout(); header('Location: admin.php'); exit; }

$loginError = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'login') {
    $u   = trim((string)($_POST['username'] ?? ''));
    $p   = (string)($_POST['password'] ?? '');
    $res = Auth::login($u, $p, $ip);
    if ($res['ok']) { header('Location: admin.php'); exit; }
    $loginError = $res['error'];
}

$sess      = Auth::session();
$locale    = I18n::locale();
$s         = Db::getAllSettings();
$siteTitle = $s['faucet_title'] ?? 'Elektron Net Faucet';

// ── Login page ──
if ($sess === null) { ?>
<!doctype html><html lang="<?= h($locale) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= he('admin.login') ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
</head><body class="admin">
<div class="page">
  <header class="page-header">
    <a href="index.php" class="site-logo" aria-label="<?= h($siteTitle) ?>">
      <img src="assets/logo.svg" alt="" width="64" height="64">
    </a>
    <h1 class="site-name"><?= h($siteTitle) ?></h1>
    <div class="header-nav">
      <div class="lang-switch">
        <?php foreach (I18n::LOCALES as $code => $name): ?>
          <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </header>
  <main class="card">
    <h2><?= he('admin.login') ?></h2>
    <?php if ($loginError): ?><div class="result err"><?= h($loginError) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="login">
      <label><?= he('admin.username') ?><input type="text" name="username" required autofocus></label>
      <label><?= he('admin.password') ?><input type="password" name="password" required></label>
      <button type="submit"><?= he('admin.signin') ?></button>
    </form>
  </main>
</div></body></html>
<?php exit; }

$adminId = (int)$sess['admin_id'];

// AJAX detection is intentionally redundant: in addition to the
// X-Requested-With header (which some reverse proxies strip) we also
// honour `?ajax=1` on the request URL. The JS client always sets both,
// so the server-side path is stable regardless of intermediate proxies.
// When neither is present we fall back to the legacy redirect path so
// the page still behaves sanely without JavaScript.
$isAjax  = !empty($_GET['ajax'])
        || !empty($_POST['_ajax'])
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    $wb = null; $we = null;
    try { $wb = Wallet::fromSettings()->getBalance(); } catch (\Throwable $e) { $we = $e->getMessage(); }
    echo json_encode([
        'totalSat'   => Stats::totalSentSat(),
        'totalCount' => Stats::totalSentCount(),
        'dailySat'   => Stats::spentLastDaySat(),
        'hourlySat'  => Stats::spentLastHourSat(),
        'walletBal'  => $wb,
        'walletErr'  => $we,
    ]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'claims') {
    header('Content-Type: application/json');
    echo json_encode(Stats::recentClaims(50));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check((string)($_POST['csrf'] ?? ''))) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
        http_response_code(403); exit('CSRF');
    }

    $jsonReply = function(bool $ok, string $msg = '', array $extra = []) use ($isAjax): never {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg], $extra));
        } else {
            // No-JS fallback: redirect to a clean URL — never leak the
            // outcome via `?_ok=…&_msg=…` query string, which used to show
            // up in the address bar when the AJAX request was misclassified.
            header('Location: admin.php');
        }
        exit;
    };

    if ($action === 'save_settings') {
        $fields = [
            'faucet_title','faucet_message','amount_elek','daily_budget','hourly_budget',
            'per_addr_cooldown_h','per_ip_cooldown_h','default_lang',
            'rpc_host','rpc_port','rpc_user','wallet_name','sender_addr',
            'hcaptcha_site','explorer_url',
        ];
        foreach ($fields as $f) {
            if (array_key_exists($f, $_POST)) Db::setSetting($f, trim((string)$_POST[$f]));
        }
        Db::setSetting('rpc_tls_verify', empty($_POST['rpc_tls_verify']) ? '0' : '1');
        if (!empty($_POST['rpc_pass']))        Db::setSetting('rpc_pass_enc',        Crypto::encrypt((string)$_POST['rpc_pass'],        'rpc_pass'));
        if (!empty($_POST['wallet_pass']))     Db::setSetting('wallet_pass_enc',     Crypto::encrypt((string)$_POST['wallet_pass'],     'wallet_pass'));
        if (!empty($_POST['hcaptcha_secret'])) Db::setSetting('hcaptcha_secret_enc', Crypto::encrypt((string)$_POST['hcaptcha_secret'], 'hcaptcha_secret'));
        Logger::audit($adminId, 'settings_saved');
        $jsonReply(true, he('admin.saved'));
    }

    if ($action === 'change_password') {
        $new = (string)($_POST['new_password'] ?? '');
        if (strlen($new) >= 10) {
            Db::exec('UPDATE admin_users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_ARGON2ID), $adminId]);
            Logger::audit($adminId, 'password_changed');
            $jsonReply(true, he('admin.pw_changed'));
        }
        $jsonReply(false, he('admin.pw_short'));
    }

    if ($action === 'test_rpc') {
        try {
            $info = Wallet::fromSettings()->testConnection();
            $jsonReply(true, he('admin.test_ok') . ' ' . json_encode($info));
        } catch (\Throwable $e) {
            $jsonReply(false, he('admin.test_failed') . ' ' . $e->getMessage());
        }
    }

    if ($action === 'test_unlock') {
        try {
            $w = Wallet::fromSettings();
            $w->rpc()->call('walletpassphrase', [Crypto::decrypt((string)Db::getSetting('wallet_pass_enc',''), 'wallet_pass'), 1]);
            $w->rpc()->call('walletlock', []);
            $jsonReply(true, he('admin.test_ok') . ' unlocked_and_locked_ok');
        } catch (\Throwable $e) {
            $jsonReply(false, he('admin.test_failed') . ' ' . $e->getMessage());
        }
    }

    if ($action === 'drop_table') {
        $table   = (string)($_POST['table'] ?? '');
        $allowed = ['donations'];
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safe !== $table || !in_array($safe, $allowed, true)) {
            $jsonReply(false, 'Not allowed.');
        }
        try {
            Db::pdo()->exec("DROP TABLE IF EXISTS `{$safe}`");
            Logger::audit($adminId, 'table_dropped', ['table' => $safe]);
            $jsonReply(true, "Table '{$safe}' dropped.");
        } catch (\Throwable $e) {
            $jsonReply(false, $e->getMessage());
        }
    }

    if ($action === 'optimize_tables') {
        try {
            $tables = Db::fetchAll('SHOW TABLES');
            foreach ($tables as $row) {
                $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)reset($row));
                if ($t !== '') Db::pdo()->exec("OPTIMIZE TABLE `{$t}`");
            }
            Logger::audit($adminId, 'tables_optimized');
            $jsonReply(true, 'All tables optimized.');
        } catch (\Throwable $e) {
            $jsonReply(false, $e->getMessage());
        }
    }
}

$csrf            = Csrf::token();
$totalSentSat    = Stats::totalSentSat();
$totalCount      = Stats::totalSentCount();
$dailySat        = Stats::spentLastDaySat();
$hourlySat       = Stats::spentLastHourSat();
$dailyBudgetSat  = RateLimiter::elekToSat($s['daily_budget']  ?? '0');
$hourlyBudgetSat = RateLimiter::elekToSat($s['hourly_budget'] ?? '0');

$walletBalance = null; $walletError = null;
try { $walletBalance = Wallet::fromSettings()->getBalance(); }
catch (\Throwable $e) { $walletError = $e->getMessage(); }

$claims    = Stats::recentClaims(50);
$histogram = Stats::last24hHistogram();
$explorer  = $s['explorer_url'] ?? '';

$activeTables = ['settings','admin_users','claims','audit_log','sessions','login_attempts'];
$legacyTables = ['donations'];
$dbTableInfo  = Db::getTableInfo();
?>
<!doctype html>
<html lang="<?= h($locale) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= he('admin.title') ?> &mdash; <?= h($siteTitle) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/svg+xml" href="assets/logo.svg">
</head><body class="admin">

<div class="page admin">

<header class="page-header">
  <a href="index.php" class="site-logo" aria-label="<?= h($siteTitle) ?>">
    <img src="assets/logo.svg" alt="" width="64" height="64">
  </a>
  <h1 class="site-name"><?= h($siteTitle) ?></h1>
  <div class="header-nav">
    <div class="lang-switch">
      <?php foreach (I18n::LOCALES as $code => $name): ?>
        <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
      <?php endforeach; ?>
    </div>
    <span class="admin-badge"><?= he('admin.title') ?></span>
    <a href="?action=logout" class="logout-link"><?= he('admin.logout') ?></a>
  </div>
</header>

<div id="toast" class="toast" hidden></div>

<div class="stats" id="stats-section">
  <div class="kpi">
    <div class="label"><?= he('kpi.total_given') ?></div>
    <div class="value" id="v-total"><?= h(RateLimiter::satToElek($totalSentSat)) ?> ELEK</div>
    <div class="sub"   id="s-total"><?= he('kpi.payouts', ['count' => $totalCount]) ?></div>
  </div>
  <div class="kpi">
    <div class="label"><?= he('kpi.today') ?></div>
    <div class="value" id="v-daily"><?= h(RateLimiter::satToElek($dailySat)) ?> ELEK</div>
    <div class="sub"><?= he('kpi.budget', ['budget' => h(RateLimiter::satToElek($dailyBudgetSat)), 'remaining' => h(RateLimiter::satToElek(max(0,$dailyBudgetSat-$dailySat)))]) ?></div>
  </div>
  <div class="kpi">
    <div class="label"><?= he('kpi.this_hour') ?></div>
    <div class="value" id="v-hourly"><?= h(RateLimiter::satToElek($hourlySat)) ?> ELEK</div>
    <div class="sub"><?= he('kpi.budget', ['budget' => h(RateLimiter::satToElek($hourlyBudgetSat)), 'remaining' => h(RateLimiter::satToElek(max(0,$hourlyBudgetSat-$hourlySat)))]) ?></div>
  </div>
  <div class="kpi">
    <div class="label"><?= he('kpi.wallet_balance') ?></div>
    <div class="value" id="v-wallet"><?= $walletBalance !== null ? h($walletBalance).' ELEK' : '&mdash;' ?></div>
    <div class="sub"><?= $walletError ? h($walletError) : he('kpi.via_getbalance') ?></div>
  </div>
</div>

<section>
  <h2><?= he('sec.last_24h') ?></h2>
  <table>
    <thead><tr><th><?= he('tbl.hour') ?></th><th><?= he('tbl.payouts') ?></th><th><?= he('tbl.elek') ?></th></tr></thead>
    <tbody>
      <?php foreach ($histogram as $h1): ?>
        <tr><td><?= h($h1['hour']) ?></td><td><?= (int)$h1['count'] ?></td><td><?= h(RateLimiter::satToElek($h1['sat'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($histogram)): ?><tr><td colspan="3"><?= he('tbl.no_data') ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<section>
  <h2><?= he('sec.recent_claims') ?></h2>
  <div class="table-scroll">
    <table>
      <thead><tr>
        <th><?= he('tbl.id') ?></th><th><?= he('tbl.time') ?></th>
        <th><?= he('tbl.address') ?></th><th><?= he('tbl.amount') ?></th>
        <th><?= he('tbl.status') ?></th><th><?= he('tbl.tx') ?></th>
      </tr></thead>
      <tbody id="claims-body">
        <?php foreach ($claims as $c): ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= h((string)$c['created_at']) ?></td>
          <td class="mono"><?= h((string)$c['address']) ?></td>
          <td><?= h(RateLimiter::satToElek((int)$c['amount_satoshi'])) ?></td>
          <td class="status-<?= h((string)$c['status']) ?>"><?= h((string)$c['status']) ?></td>
          <td class="mono">
            <?php if (!empty($c['txid']) && $explorer): ?>
              <a target="_blank" rel="noopener" href="<?= h($explorer.$c['txid']) ?>"><?= h(substr((string)$c['txid'],0,12)) ?>&hellip;</a>
            <?php elseif (!empty($c['txid'])): ?>
              <?= h(substr((string)$c['txid'],0,12)) ?>&hellip;
            <?php elseif (!empty($c['error'])): ?>
              <span class="err-text" title="<?= h((string)$c['error']) ?>"><?= h(mb_substr((string)$c['error'],0,60)) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section>
  <h2><?= he('sec.settings') ?></h2>
  <form id="settings-form" method="post" action="admin.php?ajax=1">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="_ajax" value="1">

    <fieldset><legend><?= he('sec.faucet') ?></legend>
      <label><?= he('set.title') ?><input type="text" name="faucet_title" value="<?= h($s['faucet_title'] ?? 'Elektron Net Faucet') ?>"></label>
      <label><?= he('set.welcome_text') ?><textarea name="faucet_message" rows="2"><?= h($s['faucet_message'] ?? '') ?></textarea></label>
      <label><?= he('set.amount_per_claim') ?><input type="text" name="amount_elek" value="<?= h($s['amount_elek'] ?? '0.001') ?>" required></label>
      <label><?= he('set.daily_budget') ?><input type="text" name="daily_budget" value="<?= h($s['daily_budget'] ?? '0') ?>"></label>
      <label><?= he('set.hourly_budget') ?><input type="text" name="hourly_budget" value="<?= h($s['hourly_budget'] ?? '0') ?>"></label>
      <label><?= he('set.addr_cooldown') ?><input type="number" name="per_addr_cooldown_h" min="0" value="<?= h($s['per_addr_cooldown_h'] ?? '24') ?>"></label>
      <label><?= he('set.ip_cooldown') ?><input type="number" name="per_ip_cooldown_h" min="0" value="<?= h($s['per_ip_cooldown_h'] ?? '1') ?>"></label>
      <label><?= he('set.explorer_url') ?><input type="text" name="explorer_url" value="<?= h($s['explorer_url'] ?? '') ?>"></label>
      <label><?= he('set.default_lang') ?>
        <select name="default_lang">
          <?php foreach (I18n::LOCALES as $code => $name): ?>
            <option value="<?= h($code) ?>" <?= ($s['default_lang'] ?? 'en') === $code ? 'selected' : '' ?>><?= h($name) ?> (<?= h($code) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>

    <fieldset><legend><?= he('sec.rpc') ?></legend>
      <label><?= he('set.rpc_host') ?><input type="text" name="rpc_host" value="<?= h($s['rpc_host'] ?? '127.0.0.1') ?>" required></label>
      <label><?= he('set.rpc_port') ?><input type="number" name="rpc_port" value="<?= h($s['rpc_port'] ?? '8332') ?>"></label>
      <label><?= he('set.rpc_user') ?><input type="text" name="rpc_user" value="<?= h($s['rpc_user'] ?? '') ?>"></label>
      <label><?= he('set.rpc_pass') ?><input type="password" name="rpc_pass" autocomplete="new-password"></label>
      <label><?= he('set.wallet_name') ?><input type="text" name="wallet_name" value="<?= h($s['wallet_name'] ?? '') ?>"></label>
      <label><?= he('set.wallet_pass') ?><input type="password" name="wallet_pass" autocomplete="new-password"></label>
      <label><?= he('set.sender_addr') ?><input type="text" name="sender_addr" value="<?= h($s['sender_addr'] ?? '') ?>" placeholder="be1q&hellip;"></label>
      <label class="checkbox-label">
        <input type="checkbox" name="rpc_tls_verify" value="1" <?= ($s['rpc_tls_verify'] ?? '1') !== '0' ? 'checked' : '' ?>>
        <?= he('set.tls_verify') ?>
      </label>
      <p class="hint warn"><?= he('set.tls_verify_warn') ?></p>
    </fieldset>

    <fieldset><legend><?= he('sec.captcha') ?></legend>
      <label><?= he('set.captcha_site') ?><input type="text" name="hcaptcha_site" value="<?= h($s['hcaptcha_site'] ?? '') ?>"></label>
      <label><?= he('set.captcha_secret') ?><input type="password" name="hcaptcha_secret" autocomplete="new-password"></label>
    </fieldset>

    <button type="submit" id="save-btn"><?= he('set.save') ?></button>
  </form>

  <div class="test-rows">
    <div class="test-row">
      <button type="button" id="btn-test-rpc" class="btn-test" data-action="test_rpc"><?= he('set.test_rpc') ?></button>
      <span id="rpc-msg" class="test-msg"></span>
    </div>
    <div class="test-row">
      <button type="button" id="btn-test-unlock" class="btn-test" data-action="test_unlock"><?= he('set.test_unlock') ?></button>
      <span id="unlock-msg" class="test-msg"></span>
    </div>
  </div>

  <div class="pw-section">
    <label><?= he('set.new_admin_pass') ?></label>
    <div class="pw-row">
      <input id="new-pw" type="password" autocomplete="new-password" minlength="10">
      <button type="button" id="btn-change-pw"><?= he('set.change_pw') ?></button>
    </div>
    <div id="pw-result" class="result" hidden></div>
  </div>
</section>

<section id="sec-db-maint">
  <h2><?= he('admin.db_maint') ?></h2>
  <?php if (!empty($dbTableInfo)): ?>
  <div class="table-scroll">
    <table>
      <thead><tr>
        <th><?= he('admin.db_col_table') ?></th>
        <th><?= he('admin.db_col_rows') ?></th>
        <th><?= he('admin.db_col_size') ?></th>
        <th><?= he('admin.db_col_status') ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($dbTableInfo as $t): ?>
        <?php
          $tName    = (string)$t['TABLE_NAME'];
          $isLegacy = in_array($tName, $legacyTables, true);
          $isActive = in_array($tName, $activeTables, true);
          $kb       = round((int)$t['total_bytes'] / 1024, 1);
        ?>
        <tr id="dbrow-<?= h($tName) ?>">
          <td class="mono"><?= h($tName) ?></td>
          <td><?= number_format((int)$t['TABLE_ROWS']) ?></td>
          <td><?= h($kb) ?> KB</td>
          <td><?php
            if ($isLegacy)     echo '<span class="status-legacy">'  . he('admin.db_status_legacy') . '</span>';
            elseif ($isActive) echo '<span class="status-active">'  . he('admin.db_status_active') . '</span>';
            else               echo '<span class="status-pending">' . he('admin.db_status_unknown') . '</span>';
          ?></td>
          <td>
            <?php if ($isLegacy): ?>
              <button type="button" class="btn-del btn-drop"
                      data-table="<?= h($tName) ?>"
                      data-csrf="<?= h($csrf) ?>"
                      title="<?= he('admin.db_drop_title') ?>">
                <?= he('admin.db_drop_btn') ?>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="inline-actions" style="margin-top:14px">
    <button type="button" id="btn-optimize" data-csrf="<?= h($csrf) ?>"><?= he('admin.db_optimize') ?></button>
  </div>
  <div id="db-result" class="result" hidden></div>
</section>

</div><!-- .page.admin -->

<script>
/*
 * Admin panel client-side glue. All POSTs go to admin.php?ajax=1 with a
 * matching X-Requested-With header AND a hidden _ajax=1 form field — that
 * triple-redundancy makes detection survive proxies that strip headers and
 * is the reason an accidentally-native submit (broken JS, slow page load)
 * still hits the JSON path instead of dumping ?_ok=1&_msg=... into the
 * URL bar.
 *
 * Bindings are wrapped in safeBind() so a single missing DOM node never
 * cascades into "no buttons work at all".
 */
(function () {
  'use strict';

  var CSRF = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
  var AJAX_URL = 'admin.php?ajax=1';

  var toastEl = document.getElementById('toast');

  function showToast(msg, ok) {
    if (!toastEl) { console[ok ? 'log' : 'error']('toast:', msg); return; }
    toastEl.textContent = msg;
    toastEl.className   = 'toast ' + (ok ? 'ok' : 'err');
    toastEl.hidden      = false;
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(function () { toastEl.hidden = true; }, 3500);
  }
  function showInline(el, ok, msg) {
    if (!el) return;
    el.hidden      = false;
    el.className   = 'result ' + (ok ? 'ok' : 'err');
    el.textContent = msg;
  }
  function setLoading(btn, on) {
    if (!btn) return;
    btn.disabled = on;
    if (on) {
      btn.dataset.orig = btn.dataset.orig || btn.textContent;
      btn.textContent  = '…';
    } else if (btn.dataset.orig) {
      btn.textContent  = btn.dataset.orig;
    }
  }
  function safeBind(selectorOrEl, event, handler) {
    var el = typeof selectorOrEl === 'string'
      ? document.getElementById(selectorOrEl)
      : selectorOrEl;
    if (!el) return;
    el.addEventListener(event, handler);
  }
  function toFormData(data) {
    if (data instanceof FormData) return data;
    var f = new FormData();
    Object.keys(data || {}).forEach(function (k) { f.set(k, data[k]); });
    return f;
  }

  // Parses any response the admin.php POST handler might return, even
  // when the body is HTML (session expired, php fatal, nginx 5xx). Always
  // resolves to {ok, msg, ...} — never throws.
  async function readResponse(res) {
    var ct = res.headers.get('content-type') || '';
    if (ct.indexOf('application/json') !== -1) {
      try { return await res.json(); }
      catch (e) { return { ok: false, msg: 'Bad JSON: ' + e.message }; }
    }
    var body = '';
    try { body = await res.text(); } catch (_) { /* ignore */ }
    if (/name="username"|name=\"password\"|action=login/i.test(body)) {
      return { ok: false, msg: 'Admin session expired. Reload the page and log in again.' };
    }
    return { ok: false, msg: 'HTTP ' + res.status + ' (non-JSON): ' + body.slice(0, 200) };
  }

  async function ajaxPost(data, btn) {
    setLoading(btn, true);
    try {
      var fd = toFormData(data);
      fd.set('_ajax', '1');
      var res = await fetch(AJAX_URL, {
        method:      'POST',
        body:        fd,
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept':           'application/json',
        },
      });
      return await readResponse(res);
    } catch (e) {
      var msg = (e && e.message) ? e.message : String(e);
      showToast('Request failed: ' + msg, false);
      return { ok: false, msg: msg };
    } finally {
      setLoading(btn, false);
    }
  }

  // ── Settings form ─────────────────────────────────────────────
  var settingsForm = document.getElementById('settings-form');
  safeBind(settingsForm, 'submit', async function (e) {
    e.preventDefault();
    var btn = document.getElementById('save-btn');
    var d   = await ajaxPost(new FormData(e.target), btn);
    showToast(d.msg || (d.ok ? 'Saved' : 'Error'), d.ok);
  });

  // ── RPC / Unlock tests ────────────────────────────────────────
  var testButtons = document.querySelectorAll('.btn-test');
  function setBtnState(btn, state) {
    if (!btn) return;
    btn.classList.remove('state-testing','state-ok','state-err');
    if (state) btn.classList.add('state-' + state);
  }
  function setMsgState(el, state, text) {
    if (!el) return;
    el.classList.remove('state-testing','state-ok','state-err');
    if (state) el.classList.add('state-' + state);
    el.textContent = text || '';
  }
  async function runTest(btn) {
    var msgId = btn.id === 'btn-test-rpc' ? 'rpc-msg' : 'unlock-msg';
    var msgEl = document.getElementById(msgId);
    testButtons.forEach(function (b) { b.disabled = true; });
    setBtnState(btn, 'testing');
    setMsgState(msgEl, 'testing', 'Testing…');
    testButtons.forEach(function (b) { if (b !== btn) setBtnState(b, null); });
    var data = await ajaxPost({ action: btn.dataset.action, csrf: CSRF }, null);
    var state = data.ok ? 'ok' : 'err';
    setBtnState(btn, state);
    setMsgState(msgEl, state, data.msg || (data.ok ? 'OK' : 'Error'));
    testButtons.forEach(function (b) { b.disabled = false; });
  }
  testButtons.forEach(function (b) {
    b.addEventListener('click', function () { runTest(b); });
  });

  // ── Change admin password ─────────────────────────────────────
  safeBind('btn-change-pw', 'click', async function () {
    var pwInput = document.getElementById('new-pw');
    var pw = pwInput ? pwInput.value : '';
    var d  = await ajaxPost({ action: 'change_password', new_password: pw, csrf: CSRF }, this);
    showInline(document.getElementById('pw-result'), d.ok, d.msg || (d.ok ? 'OK' : 'Error'));
    if (d.ok && pwInput) pwInput.value = '';
  });

  // ── Stats poller ──────────────────────────────────────────────
  function satToElek(s) { return (s / 1e8).toFixed(8).replace(/\.?0+$/, '') || '0'; }
  function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
  function refreshStats() {
    fetch('admin.php?ajax=stats', {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
    }).then(function (r) { return r.json(); }).then(function (d) {
      setText('v-total',  satToElek(d.totalSat)  + ' ELEK');
      setText('s-total',  d.totalCount + ' payouts');
      setText('v-daily',  satToElek(d.dailySat)  + ' ELEK');
      setText('v-hourly', satToElek(d.hourlySat) + ' ELEK');
      if (d.walletBal !== null) setText('v-wallet', d.walletBal + ' ELEK');
    }).catch(function () { /* silent */ });
  }
  setInterval(refreshStats, 60000);

  // ── DB maintenance ────────────────────────────────────────────
  document.querySelectorAll('.btn-drop').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var table = this.dataset.table;
      if (!confirm('Drop table "' + table + '"? This cannot be undone.')) return;
      var d = await ajaxPost({ action: 'drop_table', table: table, csrf: this.dataset.csrf }, this);
      showInline(document.getElementById('db-result'), d.ok, d.msg || (d.ok ? 'Done' : 'Error'));
      if (d.ok) {
        var row = document.getElementById('dbrow-' + table);
        if (row) row.remove();
      }
    });
  });

  safeBind('btn-optimize', 'click', async function () {
    var d = await ajaxPost({ action: 'optimize_tables', csrf: this.dataset.csrf }, this);
    showInline(document.getElementById('db-result'), d.ok, d.msg || (d.ok ? 'Done' : 'Error'));
  });

  // ── Global async-error surface ────────────────────────────────
  window.addEventListener('unhandledrejection', function (e) {
    var msg = (e && e.reason && (e.reason.message || String(e.reason))) || 'Unknown error';
    showToast('Background error: ' + msg, false);
  });
  window.addEventListener('error', function (e) {
    console.error('admin error:', e.error || e.message);
  });
})();
</script>
</body></html>
