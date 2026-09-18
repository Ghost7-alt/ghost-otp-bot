# Ghost OTP Bot 👻

A Python TOTP (time-based one-time password) manager with an interactive CLI, encrypted local storage, QR-code provisioning, and optional Automaton integration.

## Security

OTP secrets are encrypted with Fernet before being written to disk. By default, the bot stores data in `~/.ghost_otp_bot/secrets.enc` and creates a private `master.key` beside it. For servers or containers, provide your own key through `GHOST_OTP_STORAGE_KEY` instead:

```bash
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
export GHOST_OTP_STORAGE_KEY="your-generated-key"
```

Treat this key as a secret. If it is lost, encrypted OTP data cannot be recovered. Never commit `master.key`, `secrets.enc`, or `.env`.

## Install

```bash
python -m venv .venv
source .venv/bin/activate       # Windows: .venv\\Scripts\\activate
python -m pip install --upgrade pip
python -m pip install -e ".[dev]"
```

## Use the interactive CLI

```bash
ghost-otp
# or
python -m ghost_otp_bot.main
```

Commands:

```text
generate alice
current alice
verify alice 123456
qrcode alice ./qr_codes/alice.png
help
exit
```

## Python API

```python
from ghost_otp_bot import GhostOTPBot

bot = GhostOTPBot()
generated = bot.execute_command("generate", "alice")
token = bot.execute_command("current", "alice")["token"]
assert bot.execute_command("verify", "alice", token)["valid"]
```

## Development

```bash
pytest
ruff check src tests
```

The optional `automaton/` submodule is detected from the repository root. The OTP functionality works without it.

## Layout

```text
ghost-otp-bot/
├── .env.example
├── .gitignore
├── .gitmodules
├── README.md
├── pyproject.toml
├── requirements.txt
├── src/ghost_otp_bot/
│   ├── __init__.py
│   ├── bot.py
│   ├── main.py
│   └── otp_manager.py
└── tests/
    ├── conftest.py
    ├── test_bot.py
    └── test_otp_manager.py
```

## License

MIT
