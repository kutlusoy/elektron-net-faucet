<?php
/**
 * Faucet configuration template.
 *
 * Production: place this file as /config.php OUTSIDE the webroot (one directory above public/).
 * The installer (public/install.php) will generate this file automatically.
 *
 * If you cannot place the file outside the webroot, the included .htaccess in public/
 * blocks direct PHP access to anything outside the file whitelist. The config.php should
 * still live in the project root (not under public/), so a missing .htaccess does not
 * expose it.
 *
 * Generate a fresh random app_key with: php -r "echo bin2hex(random_bytes(32));"
 */
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'elek_faucet',
    'db_user' => 'elek_faucet',
    'db_pass' => 'CHANGE_ME',

    // 64 hex chars = 32 random bytes. NEVER commit this to git.
    'app_key' => 'CHANGE_ME_TO_32_RANDOM_BYTES_HEX_ENCODED_000000000000000000000000',
];
