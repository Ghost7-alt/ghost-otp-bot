# Ghost OTP Bot

An admin-controlled PHP 8.1+ Telegram utility bot with MySQL persistence, TOTP tools, usage statistics, configurable anti-spam protection, user-issued API tokens, authenticated webhooks, and optional NVIDIA-powered chat.

Payment testing is deliberately sandbox-only. The bot accepts Stripe `sk_test_` keys and Stripe test PaymentMethod IDs (for example `pm_card_visa`). It never accepts, stores, or checks raw card numbers, expiry dates, CVVs, or live Stripe keys.

## Features

- User profile, individual stats, and global bot stats
- Admin ban/unban, mute/unmute, per-user stats, API-token generation, and revocation
- Configurable per-user cooldown for state-changing or provider-backed actions
- Encrypted-at-rest Stripe test keys; saved keys are only displayed masked
- Stripe test PaymentIntent command gated on a saved test key
- Encrypted TOTP secrets and local verification
- API token generator for users and admins; only SHA-256 token hashes are stored
- BIN metadata lookup (optional provider) and local IBAN checksum validation
- Optional NVIDIA-powered chat command using its OpenAI-compatible API
- Telegram messaging webhook and provider-neutral call-event webhook
- Audit messages to an optional logs chat without command arguments or secrets

## Requirements

- PHP 8.1 or newer with `curl`, `pdo_mysql`, and `sodium`
- MySQL 8+ or MariaDB 10.5+
- A Telegram bot token and public HTTPS URL

## Install

```bash
cp .env.example .env
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Put the generated value in `APP_KEY`, generate a separate unique database password, and set it as `DB_PASSWORD`. Export that same password temporarily as `GHOST_DB_PASSWORD` before creating the database user:

```bash
read -rsp 'New ghost_bot database password: ' GHOST_DB_PASSWORD
[[ "$GHOST_DB_PASSWORD" =~ ^[A-Za-z0-9_-]{32,}$ ]] || { echo 'Use at least 32 letters, digits, underscores, or hyphens.' >&2; exit 1; }
mysql -u root -p <<SQL
CREATE DATABASE ghost_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ghost_bot'@'127.0.0.1' IDENTIFIED BY '${GHOST_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ghost_bot.* TO 'ghost_bot'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
mysql -h 127.0.0.1 -u ghost_bot -p ghost_bot < database/schema.sql
unset GHOST_DB_PASSWORD
```

Point the web server document root at `public/`. For local development:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
curl http://127.0.0.1:8080/health
```

For Nginx, route missing files to `index.php` and pass PHP requests to PHP-FPM:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php-fpm/www.sock;
}
```

Register the Telegram webhook with the same secret stored in `TELEGRAM_WEBHOOK_SECRET`:

```bash
read -rsp 'Telegram bot token: ' BOT_TOKEN
read -rsp 'Telegram webhook secret: ' TELEGRAM_WEBHOOK_SECRET
curl --config - <<CURL_CONFIG
url = "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook"
request = "POST"
form = "url=https://bot.example.com/webhook/telegram"
form = "secret_token=${TELEGRAM_WEBHOOK_SECRET}"
CURL_CONFIG
unset BOT_TOKEN TELEGRAM_WEBHOOK_SECRET
```

Never commit `.env`. Rotating `APP_KEY` makes existing encrypted merchant and OTP secrets unreadable, so decrypt/re-encrypt them before a key rotation.

### NVIDIA chat

To enable `/chat`, put an NVIDIA API key in `NVIDIA_API_KEY`. The endpoint, model, timeout, maximum tokens, and reasoning budget can be changed with the `NVIDIA_*` values in `.env.example`. The API key is read only from configuration and is never included in bot output or audit logs.

## Commands

| Command | Access | Description |
| --- | --- | --- |
| `/me` | User | Profile and merchant-mode status |
| `/stats` | User | Own command usage |
| `/globalstats` | User | Global users and command totals |
| `/otpnew` | User | Create a new encrypted TOTP secret |
| `/otpverify CODE` | User | Verify a TOTP code |
| `/merchant-add sk_test_...` | User | Save an encrypted Stripe test key |
| `/merchant-key` | User | Display only a masked saved key |
| `/merchant-check pm_card_visa` | User | Run a Stripe sandbox test PaymentIntent |
| `/chat PROMPT` | User | Ask the configured NVIDIA model a question |
| `/newapi LABEL` | User | Generate an API token, shown once |
| `/myapi` | User | List token prefixes and status |
| `/key PREFIX` | User | Look up metadata for an owned token |
| `/bin 424242` | User | BIN metadata or local format result |
| `/iban ...` | User | Validate IBAN structure and checksum |
| `/ban ID`, `/unban ID` | Admin | Change ban state |
| `/mute ID`, `/unmute ID` | Admin | Change mute state |
| `/userstats ID` | Admin | View any user's usage |
| `/adminapi ID LABEL` | Admin | Generate a token for an existing user |
| `/revokeapi TOKEN_ID` | Admin | Revoke a token by numeric ID |

`ANTI_SPAM_SECONDS` controls the wait between gated actions, including `/chat`. Administrative actions are exempt. The bot attempts to delete `/merchant-add` messages after encrypting the test key; give it message-deletion permission where supported.

## HTTP API

Generate a token with `/newapi` or `/adminapi`, then send it as `Authorization: Bearer ghost_...`:

```bash
read -rsp 'Ghost API token: ' GHOST_API_TOKEN
curl --config - <<CURL_CONFIG
url = "https://bot.example.com/api/v1/me"
header = "Authorization: Bearer ${GHOST_API_TOKEN}"
CURL_CONFIG
unset GHOST_API_TOKEN
```

Use the same protected curl-config pattern for `/api/v1/bin` and `/api/v1/iban`; do not place bearer tokens directly in command arguments.

The API intentionally does not expose merchant payment testing, NVIDIA chat, or stored secrets.

## Call-event webhook

POST provider-normalized status events to `/webhook/call` with `X-Webhook-Secret`. Only the event identity and type are retained; duplicate event IDs are idempotent.

```json
{"id":"evt_123","type":"call.completed"}
```

Map the signature-verified webhook from your call provider to this small internal contract at the reverse proxy or an adapter. Do not expose this endpoint without HTTPS and a strong secret.

## Test

```bash
composer test
# or, without Composer:
php tests/run.php
```

## License

This project is licensed under the [MIT License](licenses).

Copyright © 2026 Hailey Knapp.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
