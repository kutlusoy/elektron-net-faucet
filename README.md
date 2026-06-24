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

## Installation — step by step

Plan ahead: the node and the website can live on the same machine or on
separate ones. Either way the order is **node first, website second**
because the website will ask you for the node's RPC credentials.

> **Conventions in this guide**
> - `node.example.com` = the machine running `elektron-net`.
> - `faucet.example.com` = the webhost serving the PHP files.
> - Commands prefixed `#` run as root; `$` as a normal user; `PS>` in an
>   elevated PowerShell on Windows; `cmd>` in a normal Windows cmd shell.

---

### Part 1 — Configure the node

You need an `elektron-net` daemon you control, with an encrypted wallet and
JSON-RPC enabled. The wallet's private key never leaves this machine.

#### 1.1  Locate `bitcoin.conf` (yes, the file is still called that)

- **Linux**:   `~/.bitcoin/bitcoin.conf`
- **macOS**:   `~/Library/Application Support/Bitcoin/bitcoin.conf`
- **Windows**: `%APPDATA%\Elektron\bitcoin.conf` — for example
  `C:\Users\you\AppData\Roaming\Elektron\bitcoin.conf`. Create the file if
  it doesn't exist.

#### 1.2  Enable RPC

Open `bitcoin.conf` in a text editor and add:

```ini
server=1
rpcbind=127.0.0.1
rpcallowip=127.0.0.1
rpcuser=faucetuser
rpcpassword=REPLACE_WITH_A_LONG_RANDOM_PASSWORD
```

Generate a strong RPC password (do **not** reuse passwords):

- **Linux/macOS**: `openssl rand -hex 32`
- **Windows PowerShell**: `-join ((48..57)+(65..90)+(97..122)|Get-Random -Count 48|ForEach-Object{[char]$_})`

If the node will be on a **different machine** than the faucet, do not add
the node's public IP to `rpcallowip` here — leave it `127.0.0.1` and use
one of the tunnels in [Running the node on a separate host](#running-the-node-on-a-separate-host).

#### 1.3  Restart the node and verify it's listening

- **Linux** (systemd): `# systemctl restart elektrond` (or kill/restart your
  daemon however you started it).
- **macOS**: quit and re-open the app.
- **Windows**: quit the GUI from the tray, or
  `PS> Restart-Service ElektronNode` if you set it up as a service.

Verify locally:

```bash
$ elektron-cli getblockchaininfo
```

```cmd
cmd> "C:\Program Files\Elektron\daemon\elektron-cli.exe" getblockchaininfo
```

You should see JSON output. If you get "connection refused" the node isn't
listening; if you get "401" your RPC credentials are wrong.

#### 1.4  Create and encrypt a dedicated wallet

```bash
$ elektron-cli createwallet "faucet"
$ elektron-cli -rpcwallet=faucet encryptwallet "REPLACE_WITH_A_LONG_PASSPHRASE"
```

```cmd
cmd> elektron-cli.exe createwallet "faucet"
cmd> elektron-cli.exe -rpcwallet=faucet encryptwallet "REPLACE_WITH_A_LONG_PASSPHRASE"
```

**Save the passphrase in a password manager — you'll paste it once into the
faucet's admin panel and it will be encrypted at rest in MySQL.** If you
lose it, you cannot spend from this wallet.

After `encryptwallet` the node restarts the wallet in locked state. Verify:

```bash
$ elektron-cli -rpcwallet=faucet getwalletinfo
# look for: "unlocked_until": 0
```

#### 1.5  Generate a deposit address

```bash
$ elektron-cli -rpcwallet=faucet getnewaddress "" bech32
# → be1q...
```

Copy that `be1q…` address. You will fund it from your prepaid stash in
Part 3.

#### 1.6  Wait for sync

Run `elektron-cli getblockchaininfo` until `"initialblockdownload": false`.
A faucet cannot pay out until the node is fully synced.

✅ **Node ready.** Note these values — you'll paste them into the admin panel:
RPC user, RPC password, wallet name (`faucet`), wallet passphrase, the
deposit address.

---

### Part 2 — Install the website (faucet)

#### 2.1  Pick a webhost

Any host that gives you PHP 8.1+ with the `pdo_mysql`, `curl`, `openssl`,
and `mbstring` extensions, plus a MySQL/MariaDB database. Shared hosting,
a VPS, or your own server all work.

#### 2.2  Create the MySQL database

Either via your hoster's panel (phpMyAdmin / cPanel / Plesk) or via SQL:

```sql
CREATE DATABASE elek_faucet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'elek_faucet'@'localhost' IDENTIFIED BY 'A_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON elek_faucet.* TO 'elek_faucet'@'localhost';
FLUSH PRIVILEGES;
```

Note the database name, user, password, and host (often `localhost`).

#### 2.3  Upload the files

Get the repository onto the host:

```bash
$ git clone https://github.com/kutlusoy/elektron-net-faucet.git
```

Or download the ZIP from GitHub and upload via FTP/SFTP. Final layout:

```
/var/www/elek-faucet/         ← project root (NOT served by the webserver)
├── lang/
├── sql/
├── src/
├── config.example.php
└── public/                   ← THIS is your web-server DocumentRoot
    ├── index.php
    ├── admin.php
    ├── api.php
    ├── install.php
    ├── .htaccess
    └── assets/
```

Point the web-server's DocumentRoot at `public/`. On shared hosting that
forces a single public webroot, copy everything into the webroot — the
included `public/.htaccess` blocks direct access to anything outside the
file whitelist.

Apache + HTTPS minimum vhost:

```apache
<VirtualHost *:443>
    ServerName faucet.example.com
    DocumentRoot /var/www/elek-faucet/public
    <Directory /var/www/elek-faucet/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/faucet.example.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/faucet.example.com/privkey.pem
</VirtualHost>
```

Nginx + PHP-FPM equivalent:

```nginx
server {
    listen 443 ssl http2;
    server_name faucet.example.com;
    root /var/www/elek-faucet/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/faucet.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/faucet.example.com/privkey.pem;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

**Always serve via HTTPS.** The faucet sets an HSTS header automatically
when it detects HTTPS, and admin session cookies are flagged `Secure`.

#### 2.4  Run the browser installer

Open: `https://faucet.example.com/install.php`

Pick your language at the top, then fill in:

- **Database**: host, port (3306), name, user, password — from step 2.2.
- **Admin account**: username, password (≥ 10 characters).

Click **Install**. The wizard will:

1. Generate a random 32-byte `app_key` (used to encrypt secrets at rest).
2. Write `/config.php` outside the webroot, mode 0600.
3. Import `sql/schema.sql`.
4. Create your admin user (Argon2id hash).
5. Seed safe default settings.
6. Write `/.installed` to prevent re-runs.

When you see "Installation successful":

**Delete `public/install.php`** (`rm public/install.php` or via FTP). The
lock file blocks re-runs, but removing the script entirely is best
practice.

#### 2.5  Log in and configure

Go to `https://faucet.example.com/admin.php` and log in with the admin
account you just created. In **Settings**, fill in three blocks and click
**Save**:

**Faucet**
- *Title* — shown at the top of the public page.
- *Welcome text* — short description shown to claimers.
- *Amount per claim* — e.g. `0.001` ELEK. Start small.
- *Daily / hourly budget* — caps in a rolling 24h / 1h window. `0` =
  unlimited (not recommended on mainnet).
- *Cooldown per address* — e.g. `24` (hours). One claim per address per
  day.
- *Cooldown per IP* — e.g. `1` (hour). Soft anti-spam.
- *Explorer URL prefix* — e.g. `https://explorer.example/tx/`. The txid is
  appended so successful claims show a clickable link.
- *Default language* — fallback when the visitor's browser doesn't request
  a language we ship.

**Wallet RPC** (these are the values from Part 1)
- *RPC host* — `127.0.0.1` if node and website share a machine; for a
  remote node see [Running the node on a separate host](#running-the-node-on-a-separate-host).
- *RPC port* — `8332` (Bitcoin-Core default).
- *RPC user* — `faucetuser` (whatever you put in `bitcoin.conf`).
- *RPC password* — the long random string from step 1.2. **Leaving this
  field blank during a later save keeps the existing value.**
- *Wallet name* — `faucet` (the name you used in step 1.4).
- *Wallet passphrase* — the passphrase from `encryptwallet` in step 1.4.
- *Sender address* — purely informational, shown in the admin UI.
  `sendtoaddress` picks UTXOs automatically.

**hCaptcha** (strongly recommended — bots will find you)
1. Sign up at <https://www.hcaptcha.com/> (free tier is fine).
2. Create a new site for your faucet domain.
3. Copy the **Site key** and **Secret key** into the form.

After saving, click **Test RPC connection** (you should see
`getwalletinfo` JSON) and then **Test wallet unlock** (should report
`unlocked_and_locked_ok`). Both green = wired up correctly.

---

### Part 3 — Fund and go live

1. From your prepaid `be1q…` address, send some ELEK to the deposit
   address from step 1.5. Keep the hot wallet small (e.g. a week of
   payouts) and top it up as needed.
2. Wait for at least one confirmation. The admin dashboard's
   **Wallet balance** kpi updates live via `getbalance`.
3. Open `https://faucet.example.com/` in a private window, paste a
   testnet/personal address, solve the captcha, and submit. You should
   get a real `txid` link.
4. Verify on your block explorer that the transaction is broadcast.

🎉 **Live.** Watch the **Today** and **This hour** kpis to confirm the
budget is being enforced as you expect, then share the URL.

---

### Common pitfalls

| Symptom                                       | Likely cause                                                                              |
|-----------------------------------------------|-------------------------------------------------------------------------------------------|
| `Test RPC connection` → "Connection refused"  | Node isn't running, or `rpcbind` doesn't include the interface the faucet connects to.    |
| `Test RPC connection` → "401 Unauthorized"    | `rpc_user`/`rpc_pass` in the admin panel doesn't match `bitcoin.conf`.                    |
| `Test wallet unlock` → "wallet not found"     | `Wallet name` in admin doesn't match — the node has no wallet with that name. Re-check step 1.4. |
| `Test wallet unlock` → "passphrase incorrect" | The passphrase in admin doesn't match what you used in `encryptwallet`.                   |
| Claim returns "Payout failed"                 | Insufficient wallet balance, or fee estimation failed (node not fully synced).            |
| Public page shows "Faucet is disabled"        | `amount_per_claim` is `0`. Set it in the admin panel.                                     |
| Installer says "Already installed"            | Delete `.installed` in the project root to re-run.                                        |

---

## Running the node on a separate host

If the elektron-net node is on a different machine with a fixed IP, **do not
simply open RPC port 8332 to the internet.** Bitcoin-Core-compatible RPC sends
credentials with HTTP Basic Auth, in cleartext, and has no rate-limit of its
own. Use one of the three patterns below; they cover essentially every
deployment.

> **Quick chooser**
> - You have shell/root on both sides → **Pattern A (SSH tunnel)**.
> - You want a permanent private network → **Pattern B (WireGuard)**.
> - The faucet runs on **shared hosting with no shell access** (FTP/SFTP only,
>   no SSH, no system services, no VPN client) → **Pattern C (TLS terminator
>   on the node side)**. You only configure things on the node host; the
>   webserver just gets an `https://…` URL pasted into the admin panel.

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

Choose this if the faucet runs on **shared hosting with FTP/SFTP only** (no
SSH, no system services, no VPN client), on a managed PaaS, or when several
faucet hosts behind a CDN need to reach the same node. All the setup
happens on the **node host** — on the webserver you just paste an
`https://…` URL into the admin panel.

#### Prerequisites
- A domain or subdomain pointing at the node's public IP (e.g.
  `node.example.com` → `<node-public-ip>`).
- Inbound TCP 443 open on the node host (firewall + router).
- A valid TLS certificate. Use **Let's Encrypt** — it's free and the cert
  manager (Caddy / certbot) handles renewal automatically.
- Know either the **fixed public IP of your webserver** (for IP
  allow-listing) or accept that you'll add HTTP Basic Auth as a second
  layer instead — see "When the webserver has no fixed IP" below.

#### Variant 1 — Caddy on the node host (easiest, auto-HTTPS)

```caddyfile
node.example.com {
    @allow remote_ip <webserver-public-ip>
    reverse_proxy @allow 127.0.0.1:8332
    respond 403
}
```

That's the entire config. Save as `/etc/caddy/Caddyfile` and `systemctl
restart caddy` — Caddy fetches and renews the Let's Encrypt cert by
itself. On Windows: same `Caddyfile`, then `caddy.exe service install`.

#### Variant 2 — nginx on the node host

```nginx
server {
    listen 443 ssl http2;
    server_name node.example.com;
    ssl_certificate     /etc/letsencrypt/live/node.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/node.example.com/privkey.pem;

    allow <webserver-public-ip>/32;
    deny all;

    location / {
        proxy_pass http://127.0.0.1:8332/;
        proxy_set_header Host $host;
        proxy_read_timeout 60s;
    }
}
```

Obtain the cert once with `certbot --nginx -d node.example.com`. The
included `--nginx` plugin sets up auto-renewal as a cron job.

#### Wire it up in the faucet admin panel

Admin → Settings → Wallet RPC:

| Field            | Value                                                       |
|------------------|-------------------------------------------------------------|
| RPC host         | `https://node.example.com/`                                 |
| RPC port         | ignored when the host field includes a scheme               |
| RPC user         | the `rpcuser` from your node's `bitcoin.conf`               |
| RPC password     | the `rpcpassword` from your node's `bitcoin.conf`           |
| Wallet name      | e.g. `faucet`                                               |
| Wallet passphrase| the one used with `encryptwallet`                           |

Click **Save**, then **Test RPC connection**. Green = working.

`RpcClient` recognises the `https://` prefix automatically and uses TLS
with peer verification enabled. **Self-signed certificates fail by
design** — if you must use one, install the CA into your faucet host's
system trust store. There is no setting to disable verification, because
disabling it would defeat the purpose.

#### When the webserver has no fixed outbound IP

Some shared hosters route outbound traffic through an IP pool, so you
can't pin a single `allow <ip>;` line. Don't drop the allow-list to
`0.0.0.0/0` — add a second layer instead:

**Option 1: HTTP Basic Auth in nginx / Caddy.** The faucet's `RpcClient`
already sends `Basic` credentials for RPC. Layer another Basic-Auth check
in front using credentials *different* from the RPC ones. nginx:

```nginx
location / {
    auth_basic           "node-rpc";
    auth_basic_user_file /etc/nginx/.rpc_htpasswd;
    proxy_pass           http://127.0.0.1:8332/;
}
```

Caddy:

```caddyfile
node.example.com {
    basic_auth {
        gate $2a$14$...bcrypt-hash...
    }
    reverse_proxy 127.0.0.1:8332
}
```

Then in the admin panel set
`RPC host = https://gate:layer-password@node.example.com/`. The faucet's
URL parser passes the userinfo straight into the Basic-Auth header.
The inner RPC password is unchanged.

**Option 2: Client certificates.** mTLS in nginx (`ssl_client_certificate`
+ `ssl_verify_client on`). Stronger than Basic Auth, but requires
managing client cert files on the faucet host — usually only worth it
for high-value setups.

**Option 3: Cheap in-between VPS.** If neither side fits, rent a €3-5/month
VPS in the middle: WireGuard between VPS and node, TLS terminator on the
VPS, IP-allow the VPS on the node, and the faucet calls the VPS over
HTTPS. This buys you a fixed IP plus root access for the cost of a coffee
per month.

### Node on Windows

All three patterns work when `elektron-net` runs on a Windows machine.
Adjust as follows:

**`bitcoin.conf` location.** Typically
`%APPDATA%\Elektron\bitcoin.conf` (e.g.
`C:\Users\<you>\AppData\Roaming\Elektron\bitcoin.conf`). Create it if it
doesn't exist. Use forward slashes or escaped backslashes in any `datadir=`
line. After editing, restart the node (GUI: File → Exit; CLI: stop the
service / `elektron-cli stop`).

**Run the node unattended.** Either:

- Use the official Windows installer's "Start with Windows" option, or
- Install it as a Windows Service with `nssm`:

  ```cmd
  nssm install ElektronNode "C:\Program Files\Elektron\daemon\elektrond.exe"
  nssm set ElektronNode AppParameters "-datadir=C:\elektron\data"
  nssm set ElektronNode Start SERVICE_AUTO_START
  nssm start ElektronNode
  ```

**Windows Defender Firewall.** For Pattern A (SSH) open inbound TCP 22.
For Pattern B (WireGuard) open inbound UDP 51820 (or whatever port you
chose). For Pattern C (TLS) open inbound TCP 443. Use
`New-NetFirewallRule` in an elevated PowerShell:

```powershell
New-NetFirewallRule -DisplayName "OpenSSH" -Direction Inbound -Protocol TCP -LocalPort 22 -Action Allow
```

**Pattern A on Windows — OpenSSH Server.** Windows 10/11 and Server 2019+
ship with the OpenSSH Server as an optional feature:

```powershell
Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0
Set-Service -Name sshd -StartupType Automatic
Start-Service sshd
```

Create a dedicated local user `faucet` (no admin rights), then put the
faucet host's public key into `C:\Users\faucet\.ssh\authorized_keys`.
**The OpenSSH `permitopen` and `command=` restrictions work the same as on
Linux** — paste the same single-line key entry shown above. Fix permissions
once:

```powershell
icacls C:\Users\faucet\.ssh\authorized_keys /inheritance:r /grant "faucet:R" /grant "SYSTEM:F"
```

In the node's `bitcoin.conf`, keep `rpcbind=127.0.0.1` / `rpcallowip=127.0.0.1`.
The Linux faucet host's `autossh` systemd unit connects to
`faucet@windows-node-ip` exactly as in Pattern A above.

**Pattern B on Windows — WireGuard.** Install the official
[WireGuard for Windows](https://www.wireguard.com/install/) GUI. Paste the
node-side `[Interface]/[Peer]` config from Pattern B into the GUI's
"Add Tunnel → Add empty tunnel" dialog. The GUI activates the tunnel as a
Windows service. In `bitcoin.conf` use the WireGuard interface's address
(e.g. `rpcbind=10.0.0.2`).

**Pattern C on Windows — TLS terminator.** Three good options, pick one:

- **Caddy for Windows** (easiest; automatic Let's Encrypt). A 3-line
  `Caddyfile` does it:

  ```
  node.example.com {
      @allow remote_ip <faucet-public-ip>
      reverse_proxy @allow 127.0.0.1:8332
      respond 403
  }
  ```

  Run as a service: `caddy.exe service install`.

- **nginx for Windows** with the same config snippet shown above.
- **IIS + URL Rewrite + ARR** if you already operate IIS — set up a reverse
  proxy to `http://127.0.0.1:8332/` and bind a TLS certificate.

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
