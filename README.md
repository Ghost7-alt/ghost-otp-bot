![#](https://img.shields.io/badge/status-development-yellow)
![Python](https://img.shields.io/badge/python-3.8%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)

# Ghost OTP Bot 👻

A Python-based OTP (One-Time Password) authentication bot with **two-way communication** capabilities via **Automaton** library integration. Generate, verify, and manage TOTP-based OTP tokens with ease.

## Features

✨ **Core Features:**
- 🔐 OTP token generation using TOTP (Time-based One-Time Password)
- ✅ OTP token verification and validation
- 📱 QR code generation for easy authenticator app setup
- 🔄 Two-way communication through Automaton integration
- 📊 Event-based architecture for extensibility
- 🎯 Simple command-based interface
- 🤖 NVIDIA-powered chat command

## Architecture

```
ghost-otp-bot/
├── src/ghost_otp_bot/
│   ├── __init__.py           # Package initialization
│   ├── bot.py                # Main bot class
│   ├── otp_manager.py        # OTP operations
│   └── main.py               # Entry point
├── automaton/                # Automaton submodule (Git submodule)
├── requirements.txt          # Dependencies
├── setup.py                  # Package configuration
└── README.md                 # This file
```

## Dependencies

### Required
- **pyotp** - OTP token generation and verification
- **qrcode** - QR code generation
- **Pillow** - Image processing for QR codes
- **python-dotenv** - Environment configuration

### Optional
- **Automaton** - Two-way communication interface (Git submodule)

## Installation

### 1. Clone with Submodule
```bash
git clone --recurse-submodules https://github.com/Ghost7-alt/ghost-otp-bot.git
cd ghost-otp-bot
```

If you already cloned without submodules:
```bash
git submodule update --init --recursive
```

### 2. Install Dependencies
```bash
pip install -r requirements.txt
```

### 3. Install Package (Optional)
```bash
pip install -e .
```

## Usage

### Running the Bot

```bash
python src/ghost_otp_bot/main.py
```

### Programmatic Usage

```python
from ghost_otp_bot import GhostOTPBot

# Initialize bot
bot = GhostOTPBot()

# Initialize Automaton (optional)
bot.initialize_automaton()

# Generate OTP for a user
result = bot.execute_command('generate', 'john_doe')
print(result)
# Output:
# {
#     'status': 'success',
#     'secret': 'JBSWY3DPEBLW64TMMQ======',
#     'uri': 'otpauth://totp/john_doe?secret=JBSWY3DPEBLW64TMMQ%3D%3D%3D%3D%3D%3D&issuer=Ghost+OTP+Bot',
#     'message': 'OTP secret generated for john_doe'
# }

# Verify a token
result = bot.execute_command('verify', 'john_doe', '123456')
print(result)
# Output: {'status': 'success', 'valid': True, 'message': 'Token valid for john_doe'}

# Generate QR code
result = bot.execute_command('qrcode', 'john_doe', './qr_codes/john_doe.png')
print(result)

# Get current token
result = bot.execute_command('current', 'john_doe')
print(result)
# Output: {'status': 'success', 'token': '123456', 'username': 'john_doe'}
```

### Using OTPManager Directly

```python
from ghost_otp_bot import OTPManager

otp_mgr = OTPManager()

# Generate secret
secret = otp_mgr.generate_secret('alice')

# Get provisioning URI
uri = otp_mgr.get_provisioning_uri('alice')

# Generate QR code (returns bytes)
qr_bytes = otp_mgr.generate_qr_code('alice')

# Verify token
is_valid = otp_mgr.verify_token('alice', '123456')

# Get current token
token = otp_mgr.get_current_token('alice')
```

## Available Commands

| Command | Args | Description |
|---------|------|-------------|
| `generate` | `username [issuer]` | Generate new OTP secret |
| `verify` | `username token` | Verify OTP token |
| `qrcode` | `username [output_path]` | Generate QR code |
| `current` | `username` | Get current OTP token |
| `chat` | `prompt` | Ask the configured NVIDIA model a question |
| `help` | - | Show available commands |

## Automaton Integration

The bot includes **Git Submodule** integration with Automaton for two-way communication:

### Why Submodule?
✅ Keep Automaton as a separate project  
✅ Easy to sync with upstream updates  
✅ Clean separation of concerns  
✅ Version control for Automaton changes  

### Accessing Automaton in Code

```python
bot = GhostOTPBot()

# Initialize Automaton
if bot.initialize_automaton():
    print("Automaton ready!")
    # Use bot.automaton to access Automaton functionality
else:
    print("Automaton not available")
```

### Updating Automaton

```bash
# Update to latest from your fork
git submodule update --remote

# Update to specific version
cd automaton
git checkout <commit-or-tag>
cd ..
git add automaton
git commit -m "Update automaton submodule to <version>"
```

## Event System

The bot supports an event-based architecture for extensibility:

```python
def on_otp_generated(data):
    print(f"OTP generated for {data['username']}")
    # Send notification, log, etc.

bot.register_listener('otp_generated', on_otp_generated)

# When OTP is generated, event is emitted
bot.execute_command('generate', 'user')
```

## Configuration

Create a `.env` file from `.env.example`:

```bash
cp .env.example .env
```

Edit `.env` with your settings.

### NVIDIA Chat

Set `NVIDIA_API_KEY` in `.env` and use the `chat` command:

```bash
NVIDIA_API_KEY=nvapi-your-key
```

```text
ghost-otp> chat Explain how TOTP verification works
```

The integration uses NVIDIA's OpenAI-compatible `/v1/chat/completions` endpoint
with the Nemotron model and settings shown in `.env.example`. The API key is
read only from the environment and is never included in bot output.

## Development

### Project Structure for Future Features

```
ghost-otp-bot/
├── src/ghost_otp_bot/
│   ├── interfaces/           # Different communication interfaces
│   │   ├── cli.py            # Command-line interface
│   │   ├── api.py            # REST API interface
│   │   └── automaton.py      # Automaton interface
│   ├── storage/              # Data persistence
│   │   ├── secrets.py        # Secret management
│   │   └── database.py       # Database backend
│   ├── security/             # Security features
│   │   ├── encryption.py     # Secret encryption
│   │   └── auth.py           # Authentication
│   └── ...
├── tests/                    # Unit and integration tests
└── docs/                     # Documentation
```

### Running Tests

```bash
pytest tests/
```

### Code Style

Follow PEP 8:
```bash
pip install flake8
flake8 src/
```

## Contributing

1. Create a feature branch
2. Make your changes
3. Test thoroughly
4. Submit a pull request

## License

MIT License - See LICENSE file for details

## Roadmap

- [ ] REST API interface
- [ ] Database backend for secret storage
- [ ] Encryption for stored secrets
- [ ] Backup/restore functionality
- [ ] Multi-factor authentication support
- [ ] Integration tests with Automaton
- [ ] Docker containerization
- [ ] CLI improvements with better UX

## Support

For issues and questions:
- 📝 Open an issue on GitHub
- 💬 Discuss in GitHub Discussions (if enabled)
- 📧 Contact the maintainer

---

**Ghost7-alt** | 2026
