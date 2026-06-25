<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/I18n.php';
\ElektronFaucet\I18n::boot('en');
if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    \ElektronFaucet\I18n::setLocale($_GET['lang']);
}

$lockFile = dirname(__DIR__) . '/.installed';
if (is_file($lockFile)) {
    http_response_code(403);
    exit(__('inst.already'));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ElektronFaucet\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) require_once $file;
});

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['step'] ?? '') === 'install') {
    $dbHost    = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort    = (int)($_POST['db_port'] ?? 3306);
    $dbName    = trim((string)($_POST['db_name'] ?? ''));
    $dbUser    = trim((string)($_POST['db_user'] ?? ''));
    $dbPass    = (string)($_POST['db_pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    if ($dbName === '' || $dbUser === '') $errors[] = __('inst.err.db_required');
    if ($adminUser === '' || strlen($adminPass) < 10) $errors[] = __('inst.err.admin_required');

    if (empty($errors)) {
        try {
            $appKey = bin2hex(random_bytes(32));
            $cfgPath = dirname(__DIR__) . '/config.php';
            $cfg = "<?php\nreturn [\n"
                 . "    'db_host' => " . var_export($dbHost, true) . ",\n"
                 . "    'db_port' => " . var_export($dbPort, true) . ",\n"
                 . "    'db_name' => " . var_export($dbName, true) . ",\n"
                 . "    'db_user' => " . var_export($dbUser, true) . ",\n"
                 . "    'db_pass' => " . var_export($dbPass, true) . ",\n"
                 . "    'app_key' => " . var_export($appKey, true) . ",\n"
                 . "];\n";
            if (file_put_contents($cfgPath, $cfg) === false) {
                throw new \RuntimeException(__('inst.err.cfg_write'));
            }
            @chmod($cfgPath, 0600);

            \ElektronFaucet\Config::load($cfgPath);

            $schema = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
            if ($schema === false) throw new \RuntimeException(__('inst.err.schema'));
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
                if ($stmt === '') continue;
                if (str_starts_with($stmt, '--')) continue;
                \ElektronFaucet\Db::pdo()->exec($stmt);
            }

            \ElektronFaucet\Db::exec(
                'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
                [$adminUser, password_hash($adminPass, PASSWORD_ARGON2ID)]
            );

            $defaults = [
                'faucet_title'        => 'Elektron Net Faucet',
                'faucet_message'      => 'Claim some free ELEK!',
                'amount_elek'         => '0.001',
                'daily_budget'        => '1',
                'hourly_budget'       => '0.1',
                'per_addr_cooldown_h' => '24',
                'per_ip_cooldown_h'   => '1',
                'rpc_host'            => '127.0.0.1',
                'rpc_port'            => '8332',
                'default_lang'        => \ElektronFaucet\I18n::locale(),
            ];
            foreach ($defaults as $k => $v) \ElektronFaucet\Db::setSetting($k, $v);

            file_put_contents($lockFile, date('c'));
            @chmod($lockFile, 0600);

            $done = true;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            @unlink(dirname(__DIR__) . '/config.php');
        }
    }
}
$locale = \ElektronFaucet\I18n::locale();
$siteTitle = 'Elektron Net Faucet';
?><!doctype html>
<html lang="<?= h($locale) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= he('inst.title') ?></title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="page">

<header class="page-header">
  <a href="index.php" class="site-logo" aria-label="<?= h($siteTitle) ?>">
    <img src="assets/logo.svg" alt="" width="64" height="64">
  </a>
  <h1 class="site-name"><?= h($siteTitle) ?></h1>
  <div class="header-nav">
    <div class="lang-switch">
      <?php foreach (\ElektronFaucet\I18n::LOCALES as $code => $name): ?>
        <a class="<?= $code === $locale ? 'active' : '' ?>" href="?lang=<?= h($code) ?>"><?= h($code) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<main class="card">
  <h2><?= he('inst.title') ?></h2>

  <?php if ($done): ?>
    <div class="result ok">
      <?= he('inst.done') ?><br>
      <strong><?= __('inst.delete_installer') ?></strong><br>
      <a href="admin.php"><?= he('inst.go_admin') ?></a>
    </div>
  <?php else: ?>
    <?php foreach ($errors as $e): ?><div class="result err"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="step" value="install">
      <fieldset><legend><?= he('inst.db') ?></legend>
        <label><?= he('inst.host') ?><input name="db_host" value="<?= h($_POST['db_host'] ?? '127.0.0.1') ?>" required></label>
        <label><?= he('inst.port') ?><input name="db_port" type="number" value="<?= h($_POST['db_port'] ?? '3306') ?>" required></label>
        <label><?= he('inst.db_name') ?><input name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" required></label>
        <label><?= he('inst.db_user') ?><input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" required></label>
        <label><?= he('inst.db_pass') ?><input name="db_pass" type="password"></label>
      </fieldset>
      <fieldset><legend><?= he('inst.admin') ?></legend>
        <label><?= he('inst.user') ?><input name="admin_user" required></label>
        <label><?= he('inst.pass_min10') ?><input name="admin_pass" type="password" minlength="10" required></label>
      </fieldset>
      <button type="submit"><?= he('inst.install') ?></button>
    </form>
  <?php endif; ?>
</main>

</div>
</body></html>
