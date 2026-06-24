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

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'logout') {
    Auth::logout();
    header('Location: admin.php');
    exit;
}

$loginError = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'login') {
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');
    $res = Auth::login($u, $p, $ip);
    if ($res['ok']) {
        header('Location: admin.php');
        exit;
    }
    $loginError = $res['error'];
}

$sess = Auth::session();
$locale = I18n::locale();

if ($sess === null) {
    ?><!doctype html><html lang="<?= h($locale) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= he('admin.login') ?></title><link rel="stylesheet" href="assets/style.css"></head><body>
    <main class="card">
      <div class="lang-switch">
        <?php foreach (I18n::LOCALES as $code => $name): ?>
          <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
        <?php endforeach; ?>
      </div>
      <h1><?= he('admin.login') ?></h1>
      <?php if (!empty($loginError)): ?><div class="result err"><?= h($loginError) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <label><?= he('admin.username') ?><input type="text" name="username" required autofocus></label>
        <label><?= he('admin.password') ?><input type="password" name="password" required></label>
        <button type="submit"><?= he('admin.signin') ?></button>
      </form>
    </main></body></html><?php
    exit;
}

$adminId = (int)$sess['admin_id'];
$testResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF token invalid');
    }
    if ($action === 'save_settings') {
        $fields = [
            'faucet_title', 'faucet_message', 'amount_elek', 'daily_budget', 'hourly_budget',
            'per_addr_cooldown_h', 'per_ip_cooldown_h', 'default_lang',
            'rpc_host', 'rpc_port', 'rpc_user', 'wallet_name', 'sender_addr',
            'hcaptcha_site', 'explorer_url',
        ];
        foreach ($fields as $f) {
            if (array_key_exists($f, $_POST)) {
                Db::setSetting($f, trim((string)$_POST[$f]));
            }
        }
        Db::setSetting('rpc_tls_verify', empty($_POST['rpc_tls_verify']) ? '0' : '1');
        if (!empty($_POST['rpc_pass'])) {
            Db::setSetting('rpc_pass_enc', Crypto::encrypt((string)$_POST['rpc_pass'], 'rpc_pass'));
        }
        if (!empty($_POST['wallet_pass'])) {
            Db::setSetting('wallet_pass_enc', Crypto::encrypt((string)$_POST['wallet_pass'], 'wallet_pass'));
        }
        if (!empty($_POST['hcaptcha_secret'])) {
            Db::setSetting('hcaptcha_secret_enc', Crypto::encrypt((string)$_POST['hcaptcha_secret'], 'hcaptcha_secret'));
        }
        Logger::audit($adminId, 'settings_saved', ['fields' => $fields]);
        header('Location: admin.php?saved=1');
        exit;
    }
    if ($action === 'change_password') {
        $new = (string)($_POST['new_password'] ?? '');
        if (strlen($new) >= 10) {
            Db::exec(
                'UPDATE admin_users SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_ARGON2ID), $adminId]
            );
            Logger::audit($adminId, 'password_changed');
            header('Location: admin.php?pw=1');
            exit;
        }
        header('Location: admin.php?pw=short');
        exit;
    }
    if ($action === 'test_rpc') {
        try {
            $info = Wallet::fromSettings()->testConnection();
            $testResult = ['ok' => true, 'info' => $info];
        } catch (\Throwable $e) {
            $testResult = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    if ($action === 'test_unlock') {
        try {
            $w = Wallet::fromSettings();
            $w->rpc()->call('walletpassphrase', [Crypto::decrypt((string)Db::getSetting('wallet_pass_enc', ''), 'wallet_pass'), 1]);
            $w->rpc()->call('walletlock', []);
            $testResult = ['ok' => true, 'info' => ['unlocked_and_locked_ok' => true]];
        } catch (\Throwable $e) {
            $testResult = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

$s = Db::getAllSettings();
$csrf = Csrf::token();

$totalSentSat = Stats::totalSentSat();
$totalCount   = Stats::totalSentCount();
$dailySat     = Stats::spentLastDaySat();
$hourlySat    = Stats::spentLastHourSat();
$dailyBudgetSat  = RateLimiter::elekToSat($s['daily_budget']  ?? '0');
$hourlyBudgetSat = RateLimiter::elekToSat($s['hourly_budget'] ?? '0');

$walletBalance = null;
$walletError = null;
try { $walletBalance = Wallet::fromSettings()->getBalance(); }
catch (\Throwable $e) { $walletError = $e->getMessage(); }

$claims = Stats::recentClaims(50);
$histogram = Stats::last24hHistogram();
$explorer = $s['explorer_url'] ?? '';
?><!doctype html>
<html lang="<?= h($locale) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= he('admin.title') ?></title><link rel="stylesheet" href="assets/style.css"></head><body class="admin">
<header class="bar">
  <h1><?= he('admin.title') ?></h1>
  <nav>
    <span class="lang-switch">
      <?php foreach (I18n::LOCALES as $code => $name): ?>
        <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
      <?php endforeach; ?>
    </span>
    <a href="?action=logout"><?= he('admin.logout') ?></a>
  </nav>
</header>
<main>
  <?php if (isset($_GET['saved'])): ?><div class="result ok"><?= he('admin.saved') ?></div><?php endif; ?>
  <?php if (isset($_GET['pw'])): ?>
    <div class="result <?= $_GET['pw']==='1'?'ok':'err' ?>"><?= he($_GET['pw']==='1' ? 'admin.pw_changed' : 'admin.pw_short') ?></div>
  <?php endif; ?>
  <?php if ($testResult !== null): ?>
    <div class="result <?= $testResult['ok']?'ok':'err' ?>">
      <?= $testResult['ok']
            ? he('admin.test_ok') . ' ' . h(json_encode($testResult['info']))
            : he('admin.test_failed') . ' ' . h($testResult['error']) ?>
    </div>
  <?php endif; ?>

  <section class="stats">
    <div class="kpi"><div class="label"><?= he('kpi.total_given') ?></div><div class="value"><?= h(RateLimiter::satToElek($totalSentSat)) ?> ELEK</div><div class="sub"><?= he('kpi.payouts', ['count' => $totalCount]) ?></div></div>
    <div class="kpi"><div class="label"><?= he('kpi.today') ?></div><div class="value"><?= h(RateLimiter::satToElek($dailySat)) ?> ELEK</div><div class="sub"><?= he('kpi.budget', ['budget' => h(RateLimiter::satToElek($dailyBudgetSat)), 'remaining' => h(RateLimiter::satToElek(max(0, $dailyBudgetSat - $dailySat)))]) ?></div></div>
    <div class="kpi"><div class="label"><?= he('kpi.this_hour') ?></div><div class="value"><?= h(RateLimiter::satToElek($hourlySat)) ?> ELEK</div><div class="sub"><?= he('kpi.budget', ['budget' => h(RateLimiter::satToElek($hourlyBudgetSat)), 'remaining' => h(RateLimiter::satToElek(max(0, $hourlyBudgetSat - $hourlySat)))]) ?></div></div>
    <div class="kpi"><div class="label"><?= he('kpi.wallet_balance') ?></div><div class="value"><?= $walletBalance !== null ? h($walletBalance) . ' ELEK' : '—' ?></div><div class="sub"><?= $walletError ? he('kpi.rpc_error', ['error' => h($walletError)]) : he('kpi.via_getbalance') ?></div></div>
  </section>

  <section class="histogram">
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

  <section class="claims">
    <h2><?= he('sec.recent_claims') ?></h2>
    <table>
      <thead><tr>
        <th><?= he('tbl.id') ?></th><th><?= he('tbl.time') ?></th><th><?= he('tbl.address') ?></th>
        <th><?= he('tbl.amount') ?></th><th><?= he('tbl.status') ?></th><th><?= he('tbl.tx') ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($claims as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= h((string)$c['created_at']) ?></td>
            <td class="mono"><?= h((string)$c['address']) ?></td>
            <td><?= h(RateLimiter::satToElek((int)$c['amount_satoshi'])) ?></td>
            <td class="status-<?= h((string)$c['status']) ?>"><?= h((string)$c['status']) ?></td>
            <td class="mono">
              <?php if (!empty($c['txid'])): ?>
                <?php if ($explorer !== ''): ?>
                  <a target="_blank" rel="noopener" href="<?= h($explorer . $c['txid']) ?>"><?= h(substr((string)$c['txid'], 0, 16)) ?>…</a>
                <?php else: ?>
                  <?= h(substr((string)$c['txid'], 0, 16)) ?>…
                <?php endif; ?>
              <?php elseif (!empty($c['error'])): ?>
                <span class="err-text" title="<?= h((string)$c['error']) ?>"><?= h(mb_substr((string)$c['error'], 0, 60)) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="settings">
    <h2><?= he('sec.settings') ?></h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_settings">

      <fieldset><legend><?= he('sec.faucet') ?></legend>
        <label><?= he('set.title') ?><input type="text" name="faucet_title" value="<?= h($s['faucet_title'] ?? 'Elektron Net Faucet') ?>"></label>
        <label><?= he('set.welcome_text') ?><textarea name="faucet_message" rows="2"><?= h($s['faucet_message'] ?? '') ?></textarea></label>
        <label><?= he('set.amount_per_claim') ?><input type="text" name="amount_elek" value="<?= h($s['amount_elek'] ?? '0.001') ?>" pattern="^\d+(\.\d{1,8})?$" required></label>
        <label><?= he('set.daily_budget') ?><input type="text" name="daily_budget" value="<?= h($s['daily_budget'] ?? '0') ?>" pattern="^\d+(\.\d{1,8})?$"></label>
        <label><?= he('set.hourly_budget') ?><input type="text" name="hourly_budget" value="<?= h($s['hourly_budget'] ?? '0') ?>" pattern="^\d+(\.\d{1,8})?$"></label>
        <label><?= he('set.addr_cooldown') ?><input type="number" name="per_addr_cooldown_h" min="0" value="<?= h($s['per_addr_cooldown_h'] ?? '24') ?>"></label>
        <label><?= he('set.ip_cooldown') ?><input type="number" name="per_ip_cooldown_h" min="0" value="<?= h($s['per_ip_cooldown_h'] ?? '1') ?>"></label>
        <label><?= he('set.explorer_url') ?><input type="text" name="explorer_url" value="<?= h($s['explorer_url'] ?? '') ?>" placeholder="https://explorer.example/tx/"></label>
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
        <label><?= he('set.rpc_port') ?><input type="number" name="rpc_port" value="<?= h($s['rpc_port'] ?? '8332') ?>" required></label>
        <label><?= he('set.rpc_user') ?><input type="text" name="rpc_user" value="<?= h($s['rpc_user'] ?? '') ?>" autocomplete="off"></label>
        <label><?= he('set.rpc_pass') ?><input type="password" name="rpc_pass" autocomplete="new-password"></label>
        <label><?= he('set.wallet_name') ?><input type="text" name="wallet_name" value="<?= h($s['wallet_name'] ?? '') ?>"></label>
        <label><?= he('set.wallet_pass') ?><input type="password" name="wallet_pass" autocomplete="new-password"></label>
        <label><?= he('set.sender_addr') ?><input type="text" name="sender_addr" value="<?= h($s['sender_addr'] ?? '') ?>" placeholder="be1q…"></label>
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

      <button type="submit"><?= he('set.save') ?></button>
    </form>

    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button type="submit" name="action" value="test_rpc"><?= he('set.test_rpc') ?></button>
      <button type="submit" name="action" value="test_unlock"><?= he('set.test_unlock') ?></button>
    </form>

    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="change_password">
      <label><?= he('set.new_admin_pass') ?><input type="password" name="new_password" autocomplete="new-password" minlength="10"></label>
      <button type="submit"><?= he('set.change_pw') ?></button>
    </form>
  </section>
</main>
</body></html>
