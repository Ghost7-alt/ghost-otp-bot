# Ghost OTP Bot 👻

A Python-based OTP (One-Time Password) manager for generating, verifying, and provisioning TOTP codes. The project includes an interactive CLI, QR-code generation, encrypted local secret storage, and optional Automaton integration.

## Features

- Generate secure TOTP secrets
- Verify OTP tokens against the active time window
- Produce provisioning URIs for authenticator apps
- Generate QR codes for setup
- Persist secrets with encrypted storage
- Run as a simple interactive CLI or importable Python library
- Support optional Automaton integration when the submodule is available

## Security model

Secrets are encrypted before they are written to disk using Fernet symmetric encryption.

By default, the app stores:

- `~/.ghost_otp_bot/secrets.enc` for encrypted OTP data
- `~/.ghost_otp_bot/master.key` for the encryption key

For production or deployment systems, it is strongly recommended to set a key explicitly via environment variable:

```bash
export GHOST_OTP_STORAGE_KEY="<your-fernet-key>"
```

To generate a valid key:

```bash
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
```

Important:
- Treat the key like a password.
- Never commit `master.key`, `secrets.enc`, or `.env` files.
- If the key is lost, the encrypted secrets cannot be recovered.

## Installation

### 1) Clone the repository

```bash
git clone https://github.com/Ghost7-alt/ghost-otp-bot.git
cd ghost-otp-bot
```

### 2) Create a virtual environment

```bash
python -m venv .venv
source .venv/bin/activate
# On Windows PowerShell:
# .\.venv\Scripts\Activate.ps1
```

### 3) Install the package

```bash
python -m pip install --upgrade pip
python -m pip install -e ".[dev]"
```

## Usage

### Interactive CLI

```bash
ghost-otp
```

or:

```bash
python -m ghost_otp_bot.main
```

Available commands:

```text
generate alice
current alice
verify alice 123456
qrcode alice ./qr_codes/alice.png
help
exit
```

Examples:

```bash
ghost-otp
ghost-otp> generate alice
ghost-otp> current alice
ghost-otp> verify alice 123456
ghost-otp> qrcode alice ./qr_codes/alice.png
ghost-otp> help
ghost-otp> exit
```

### Python API

```python
from ghost_otp_bot import GhostOTPBot

bot = GhostOTPBot()

result = bot.execute_command("generate", "alice")
print(result)

current = bot.execute_command("current", "alice")
print(current)

verification = bot.execute_command("verify", "alice", current["token"])
print(verification)
```

## Project layout

```text
ghost-otp-bot/
├── .env.example
├── .gitignore
├── .gitmodules
├── README.md
├── pyproject.toml
├── requirements.txt
├── src/
│   └── ghost_otp_bot/
│       ├── __init__.py
│       ├── bot.py
│       ├── main.py
│       └── otp_manager.py
├── tests/
│   ├── conftest.py
│   ├── test_bot.py
│   └── test_otp_manager.py
└── automaton/                  # optional submodule
```

## Configuration

The repository includes a template configuration file:

```bash
cp .env.example .env
```

The default environment template contains values for the app name, issuer, QR output path, and optional Automaton path.

## Development

Run tests:

```bash
pytest
```

Run linting:

```bash
ruff check src tests
```

## Automaton integration

The bot will attempt to detect and initialize Automaton automatically when the `automaton/` directory is present in the project root.

If the submodule has not been initialized, the OTP bot still works without it, but the optional two-way communication layer is unavailable.

```bash
git submodule update --init --recursive
```

## License

MIT
