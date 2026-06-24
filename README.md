# Elektron Net Faucet

A self-hostable faucet for the Elektron Net (ELEK) cryptocurrency.
Pure **PHP 8.1+ / MySQL** — drop on any LAMP/LEMP webhost, install through a
browser-based setup wizard, configure everything from the admin panel.

- Real on-chain payouts via JSON-RPC against an `elektron-net` node.
- **The faucet never sees a private key.** The wallet stays encrypted on the
  node; the PHP app only knows the wallet passphrase (encrypted in MySQL) and
  unlocks the wallet for ~10 seconds per payout.
- Configurable amount per claim, daily budget, hourly budget,
  per-address cooldown, per-IP cooldown.
- Live statistics: total given away, today / this hour with remaining budget,
  24-hour histogram, wallet balance, last 50 claims with txids.
- Bot defense: **hCaptcha** server-side verification.
- Bech32 / bech32m address validation for ELEK (HRP `be`, `be1q…` / `be1p…`).
- **Multilingual**: English, German, Spanish, Italian, French, Portuguese.
  User-switchable, with `Accept-Language` auto-detection.
- Setup-wizard at `install.php` — writes `config.php`, imports the schema,
  creates the admin user, then self-locks.

---

## Requirements

| Component       | Version                                     |
|-----------------|---------------------------------------------|
| PHP             | 8.1 or newer (`pdo_mysql`, `curl`, `openssl`, `mbstring`) |
| MySQL/MariaDB   | MariaDB 10.4+ / MySQL 5.7+                  |
| Web server      | Apache (`.htaccess` included) or Nginx      |
| elektron-net    | A reachable node with JSON-RPC enabled      |

---

## Installation

### 1. Upload files

Upload the contents of this repository to your webhost so that the layout looks like:

```
/your-site/
├── config.php          ← created by the installer (NOT in git)
├── .installed          ← created by the installer (NOT in git)
├── lang/
├── sql/
├── src/
└── public/             ← THIS is your DocumentRoot
    ├── index.php
    ├── admin.php
    ├── api.php
    ├── install.php
    ├── .htaccess
    └── assets/
```

Point your web-server's DocumentRoot to `public/`. This keeps `config.php`,
`src/`, `lang/`, and `sql/` outside the webroot.

If your hosting only lets you upload into a single public webroot, copy
everything into one directory — the included `public/.htaccess` blocks direct
PHP access to anything outside the file whitelist.

### 2. Create a MySQL database

```sql
CREATE DATABASE elek_faucet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'elek_faucet'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON elek_faucet.* TO 'elek_faucet'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Run the setup wizard

Open in your browser:

```
https://your-faucet.example/install.php
```

Fill in:

- Database host, port, name, user, password.
- Admin username and password (≥ 10 characters).

The wizard will:

1. Generate a 32-byte `app_key` (used to encrypt secrets at rest).
2. Write `/config.php` (outside webroot).
3. Import `sql/schema.sql`.
4. Create the admin account.
5. Seed default settings (small amounts; safe to play with).
6. Lock itself via `.installed`.

**After success: delete `public/install.php`.** The lock file prevents
re-running, but removing the script entirely is best practice.

### 4. Prepare your elektron-net node

On the host running `elektron-net`:

```bash
# Create a fresh wallet (you can pick any name)
elektron-cli createwallet "faucet"

# Get a deposit address — fund this from your prepaid be1q… address
elektron-cli -rpcwallet=faucet getnewaddress "" bech32
# → returns: be1q...

# Encrypt the wallet (REMEMBER THIS PASSPHRASE — it goes into the faucet's admin panel)
elektron-cli -rpcwallet=faucet encryptwallet "your-very-long-passphrase"
```

In your node's `bitcoin.conf` (yes, the file is still called bitcoin.conf in
the Bitcoin-Core-derived codebase) enable RPC:

```ini
server=1
rpcuser=faucetuser
rpcpassword=a-long-random-rpc-password
rpcbind=127.0.0.1
rpcallowip=127.0.0.1
# If the faucet runs on a different host than the node, use a private network
# or SSH tunnel — never expose RPC to the internet.
```

Restart the node.

### 5. Configure the faucet

Log in at `https://your-faucet.example/admin.php` with the admin user.

In **Settings** fill in:

- **Faucet**: title, welcome text, amount per claim, daily/hourly budget,
  cooldowns, explorer URL prefix (e.g. `https://explorer.example/tx/`),
  default language.
- **Wallet RPC**: host, port (8332 by default), RPC user/password, wallet
  name (`faucet` if you followed step 4), wallet passphrase (the one you
  chose with `encryptwallet`).
- **hCaptcha**: site key + secret key from <https://www.hcaptcha.com/>.

Click **Save**, then use **Test RPC connection** and **Test wallet unlock**.
Both should report success.

### 6. Fund the faucet

Send ELEK from your prepaid `be1q…` address to the deposit address you got in
step 4. The dashboard's "Wallet balance" will update via `getbalance` after
the transaction confirms.

You're live.

---

## Running the node on a separate host

If the elektron-net node is on a different machine with a fixed IP, **do not
simply open RPC port 8332 to the internet.** Bitcoin-Core-compatible RPC sends
credentials with HTTP Basic Auth, in cleartext, and has no rate-limit of its
own. Use one of the three patterns below; they cover essentially every
deployment.

### Pattern A — SSH reverse tunnel (recommended for most operators)

The node keeps `rpcbind=127.0.0.1` (never exposed to the internet). The faucet
host opens a persistent SSH tunnel and reaches RPC locally on, say,
`127.0.0.1:18332`.

On the **node host** (`bitcoin.conf` unchanged from a standard install):

```ini
server=1
rpcbind=127.0.0.1
rpcallowip=127.0.0.1
rpcuser=faucetuser
rpcpassword=<long-random>
```

Create a dedicated UNIX user `faucet` on the node host and add the faucet
server's SSH public key to `/home/faucet/.ssh/authorized_keys` with strict
restrictions — port-forward only, no shell, no PTY:

```
command="echo no-shell allowed",no-pty,no-X11-forwarding,permitopen="127.0.0.1:8332" ssh-ed25519 AAAA... faucet@web-server
```

On the **faucet host**, install `autossh` and add a systemd unit:

```ini
# /etc/systemd/system/elek-rpc-tunnel.service
[Unit]
Description=SSH tunnel to elektron-net RPC
After=network-online.target
Wants=network-online.target

[Service]
User=www-data
Environment=AUTOSSH_GATETIME=0
ExecStart=/usr/bin/autossh -M 0 -N \
  -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
  -o ExitOnForwardFailure=yes -o StrictHostKeyChecking=accept-new \
  -L 127.0.0.1:18332:127.0.0.1:8332 \
  -i /etc/elek-faucet/id_ed25519 \
  faucet@node.example.com
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then in the faucet admin panel set `RPC host = 127.0.0.1`,
`RPC port = 18332`. No code changes needed.

### Pattern B — WireGuard / Tailscale

Both hosts join a private overlay network. The node binds RPC to the private
interface (e.g. `10.0.0.2:8332`); only the faucet host's private IP is
allowed.

Node `bitcoin.conf`:

```ini
server=1
rpcbind=127.0.0.1
rpcbind=10.0.0.2
rpcallowip=127.0.0.1
rpcallowip=10.0.0.1/32
rpcuser=faucetuser
rpcpassword=<long-random>
```

Minimal `wg0.conf` on the node:

```ini
[Interface]
Address = 10.0.0.2/24
ListenPort = 51820
PrivateKey = <node-private>

[Peer]
PublicKey = <faucet-public>
AllowedIPs = 10.0.0.1/32
```

And on the faucet host:

```ini
[Interface]
Address = 10.0.0.1/24
PrivateKey = <faucet-private>

[Peer]
PublicKey = <node-public>
Endpoint = node.example.com:51820
AllowedIPs = 10.0.0.2/32
PersistentKeepalive = 25
```

In the admin panel: `RPC host = 10.0.0.2`, `RPC port = 8332`.
Lower latency than SSH if you do many RPC calls per minute. With Tailscale,
the setup is even simpler — `tailscale up` on both hosts and use the
auto-assigned `100.x.y.z` addresses.

### Pattern C — TLS terminator (when A and B aren't available)

If you cannot run a tunnel (e.g. managed PaaS, multiple faucet hosts behind a
CDN that needs to reach the node), put a TLS terminator in front of the node
RPC with a valid certificate and either client-certificate auth or strict IP
allow-listing.

nginx snippet on the node host:

```nginx
server {
    listen 443 ssl http2;
    server_name node.example.com;
    ssl_certificate     /etc/letsencrypt/live/node.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/node.example.com/privkey.pem;

    allow <faucet-public-ip>/32;
    deny all;

    location / {
        proxy_pass http://127.0.0.1:8332/;
        proxy_set_header Host $host;
        proxy_read_timeout 60s;
    }
}
```

In the admin panel, set `RPC host = https://node.example.com/`. The faucet's
`RpcClient` recognises the `https://` prefix and uses TLS with peer
verification enabled. Self-signed certificates require their CA to be
installed in the faucet host's system trust store — there is no setting to
disable verification.

### Hard rules

- **Never** set `rpcallowip=0.0.0.0/0`.
- **Never** run Bitcoin-Core-style RPC over plain HTTP across the internet.
- Use a strong, randomly-generated `rpcpassword`. RPC credentials still
  matter even with the tunnel — they're a defence-in-depth layer.
- Keep the hot wallet small. Top it up from a cold wallet as needed.

---

## How the no-private-key approach works

You asked: *"I don't want to enter a private key anywhere — is there another
way?"* Yes — the faucet uses **wallet-level encryption on the node**:

1. The private key lives only inside the encrypted `wallet.dat` on the
   elektron-net node.
2. The faucet's MySQL stores the wallet **passphrase**, encrypted with
   AES-256-GCM using a 32-byte `app_key` that lives in `config.php`
   (outside the webroot, never in git).
3. Each payout the faucet:
   - calls `walletpassphrase("…", 10)` (unlocks for 10 seconds),
   - calls `sendtoaddress(addr, amount)`,
   - calls `walletlock` immediately afterwards.
4. The private key is never sent over the wire and never touches PHP memory.
5. If your webserver is compromised, the attacker still needs the
   `app_key` from `config.php` to decrypt the passphrase, and even then can
   only spend what's in the hot wallet — not your full prepaid stash.
   **Keep the hot wallet small.** Top it up from a cold wallet.

---

## Configuration reference

All settings (except DB credentials and `app_key`) live in the `settings`
table and are edited through the admin panel. Sensitive ones
(`rpc_pass`, `wallet_pass`, `hcaptcha_secret`) are stored as
`*_enc` columns containing AES-256-GCM ciphertext.

| Setting                | Meaning                                                                 |
|------------------------|-------------------------------------------------------------------------|
| `amount_elek`          | Amount per successful claim, in ELEK (e.g. `0.001`).                    |
| `daily_budget`         | Maximum ELEK distributed in any rolling 24h window. `0` = unlimited.    |
| `hourly_budget`        | Maximum ELEK distributed in any rolling 1h window. `0` = unlimited.     |
| `per_addr_cooldown_h`  | An address can only claim once every N hours.                           |
| `per_ip_cooldown_h`    | An IP can only claim once every N hours.                                |
| `default_lang`         | Fallback locale when the user hasn't picked one and no `Accept-Language` match. |
| `explorer_url`         | URL prefix; the txid is appended for the success link in the frontend.  |
| `rpc_host`/`rpc_port`  | Where to reach the elektron-net JSON-RPC.                               |
| `rpc_user`/`rpc_pass`  | RPC credentials (must match the node's `bitcoin.conf`).                 |
| `wallet_name`          | Sent as `/wallet/<name>` segment for multi-wallet nodes; blank = default wallet. |
| `wallet_pass`          | Passphrase used with `walletpassphrase` for each payout.                |
| `hcaptcha_site`/`hcaptcha_secret` | hCaptcha keys. Leave both blank to disable captcha (NOT recommended). |

---

## Internationalization

Locale files live under `lang/<code>.php` and return a flat
`array<string, string>`. Bundled locales:

- `en` — English (canonical source of truth)
- `de` — Deutsch
- `es` — Español
- `it` — Italiano
- `fr` — Français
- `pt` — Português

To add another language, copy `lang/en.php` to `lang/<code>.php`, translate
the values, and add the code/name pair to the `I18n::LOCALES` constant in
`src/I18n.php`. The user can pick a language from the chip row at the top of
each page; the choice is stored in the `fct_lang` cookie. Without a cookie,
the `Accept-Language` header is honored, then `default_lang`, then English.

---

## Security notes

- All DB queries use **PDO prepared statements** exclusively.
- All output is escaped through `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
  (`he()` helper).
- Address input is validated against the full bech32 / bech32m checksum
  (BIP-173 / BIP-350), not just a regex.
- CSRF tokens guard every state-changing request (claims, admin forms,
  logout).
- hCaptcha is verified **server-side** with the secret key and remote IP.
- Admin sessions are stored in the database (not files) with `HttpOnly`,
  `Secure`, `SameSite=Strict` cookies.
- Admin passwords are hashed with `PASSWORD_ARGON2ID`.
- Admin login is rate-limited (10 failures per IP per 15 minutes).
- `config.php` should be **outside the webroot**, mode `0600`, with the
  32-byte `app_key` never committed to git.
- `public/.htaccess` denies direct PHP access to anything not in the
  whitelist (`index.php`, `admin.php`, `api.php`, `install.php`).
- Always serve via HTTPS. The `Strict-Transport-Security` header is sent
  automatically when HTTPS is detected.

---

## File layout

```
elektron-net-faucet/
├── README.md
├── LICENSE
├── .gitignore
├── config.example.php          template — installer writes the real config.php
├── lang/                       <code>.php translation files
│   ├── en.php
│   ├── de.php
│   ├── es.php
│   ├── it.php
│   ├── fr.php
│   └── pt.php
├── sql/
│   └── schema.sql
├── src/                        PSR-4-like core (manual autoload in Bootstrap.php)
│   ├── Bootstrap.php
│   ├── Config.php
│   ├── Db.php
│   ├── Crypto.php              AES-256-GCM (HKDF-derived per-context key)
│   ├── RpcClient.php           JSON-RPC over HTTP
│   ├── Wallet.php              walletpassphrase → sendtoaddress → walletlock
│   ├── AddressValidator.php    BIP-173 / BIP-350 (HRP "be")
│   ├── Captcha.php             hCaptcha siteverify
│   ├── RateLimiter.php         per-addr / per-IP / hourly / daily budgets
│   ├── Stats.php
│   ├── Csrf.php
│   ├── Auth.php                admin login, DB sessions, rate-limit
│   ├── Logger.php              audit log
│   └── I18n.php                locale detection + translation helper
└── public/                     web-server DocumentRoot
    ├── index.php
    ├── admin.php
    ├── api.php
    ├── install.php             delete after install
    ├── .htaccess
    └── assets/style.css
```

---

## License

MIT. See `LICENSE`.
