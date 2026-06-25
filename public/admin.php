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
$isAjax  = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

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
            header('Location: admin.php?_ok=' . ($ok?'1':'0') . '&_msg=' . urlencode($msg));
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
  <form id="settings-form" method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_settings">

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

  <div class="inline-actions">
    <button id="btn-test-rpc"><?= he('set.test_rpc') ?></button>
    <button id="btn-test-unlock"><?= he('set.test_unlock') ?></button>
  </div>
  <div id="rpc-result" class="result" hidden></div>

  <div class="pw-section">
    <label><?= he('set.new_admin_pass') ?></label>
    <div class="pw-row">
      <input id="new-pw" type="password" autocomplete="new-password" minlength="10">
      <button id="btn-change-pw"><?= he('set.change_pw') ?></button>
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
              <button class="btn-del btn-drop"
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
    <button id="btn-optimize" data-csrf="<?= h($csrf) ?>"><?= he('admin.db_optimize') ?></button>
  </div>
  <div id="db-result" class="result" hidden></div>
</section>

</div><!-- .page.admin -->

<script>
const CSRF = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;

function setLoading(btn, on) {
  btn.disabled = on;
  btn.dataset.orig = btn.dataset.orig || btn.textContent;
  btn.textContent  = on ? '…' : btn.dataset.orig;
}
const toastEl = document.getElementById('toast');
function showToast(msg, ok) {
  toastEl.textContent = msg;
  toastEl.className   = 'toast ' + (ok ? 'ok' : 'err');
  toastEl.hidden      = false;
  clearTimeout(toastEl._t);
  toastEl._t = setTimeout(() => { toastEl.hidden = true; }, 3500);
}
function showInline(el, ok, msg) {
  el.hidden    = false;
  el.className = 'result ' + (ok ? 'ok' : 'err');
  el.textContent = msg;
}
async function ajaxPost(data, btn) {
  if (btn) setLoading(btn, true);
  try {
    const fd = data instanceof FormData ? data
      : (() => { const f = new FormData(); Object.entries(data).forEach(([k,v]) => f.set(k,v)); return f; })();
    const res = await fetch('admin.php', { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    return await res.json();
  } finally { if (btn) setLoading(btn, false); }
}

document.getElementById('settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('save-btn');
  const d   = await ajaxPost(new FormData(e.target), btn);
  showToast(d.msg || (d.ok ? 'Saved' : 'Error'), d.ok);
});

document.getElementById('btn-test-rpc').addEventListener('click', async function() {
  const d = await ajaxPost({ action:'test_rpc', csrf:CSRF }, this);
  showInline(document.getElementById('rpc-result'), d.ok, d.msg || (d.ok?'OK':'Error'));
});
document.getElementById('btn-test-unlock').addEventListener('click', async function() {
  const d = await ajaxPost({ action:'test_unlock', csrf:CSRF }, this);
  showInline(document.getElementById('rpc-result'), d.ok, d.msg || (d.ok?'OK':'Error'));
});

document.getElementById('btn-change-pw').addEventListener('click', async function() {
  const pw = document.getElementById('new-pw').value;
  const d  = await ajaxPost({ action:'change_password', new_password:pw, csrf:CSRF }, this);
  showInline(document.getElementById('pw-result'), d.ok, d.msg || (d.ok?'OK':'Error'));
  if (d.ok) document.getElementById('new-pw').value = '';
});

function satToElek(s) { return (s/1e8).toFixed(8).replace(/\.?0+$/,'') || '0'; }
function refreshStats() {
  fetch('admin.php?ajax=stats', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
      document.getElementById('v-total').textContent  = satToElek(d.totalSat)  + ' ELEK';
      document.getElementById('s-total').textContent  = d.totalCount + ' payouts';
      document.getElementById('v-daily').textContent  = satToElek(d.dailySat)  + ' ELEK';
      document.getElementById('v-hourly').textContent = satToElek(d.hourlySat) + ' ELEK';
      if (d.walletBal !== null) document.getElementById('v-wallet').textContent = d.walletBal + ' ELEK';
    }).catch(()=>{});
}
setInterval(refreshStats, 60000);

document.querySelectorAll('.btn-drop').forEach(btn => {
  btn.addEventListener('click', async function() {
    const table = this.dataset.table;
    if (!confirm('Drop table "' + table + '"? This cannot be undone.')) return;
    const d = await ajaxPost({ action:'drop_table', table, csrf:this.dataset.csrf }, this);
    showInline(document.getElementById('db-result'), d.ok, d.msg || (d.ok?'Done':'Error'));
    if (d.ok) document.getElementById('dbrow-' + table)?.remove();
  });
});

document.getElementById('btn-optimize').addEventListener('click', async function() {
  const d = await ajaxPost({ action:'optimize_tables', csrf:this.dataset.csrf }, this);
  showInline(document.getElementById('db-result'), d.ok, d.msg || (d.ok?'Done':'Error'));
});
</script>
</body></html>
