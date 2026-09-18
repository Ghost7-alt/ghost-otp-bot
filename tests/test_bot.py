from cryptography.fernet import Fernet

from ghost_otp_bot import GhostOTPBot


def test_bot_creates_qr_parent_directory(tmp_path):
    bot = GhostOTPBot(
        storage_path=str(tmp_path / "secrets.enc"),
    )
    bot.otp_manager._fernet = Fernet(bot.otp_manager._fernet._signing_key + bot.otp_manager._fernet._encryption_key)
    bot.execute_command("generate", "alice")
    output = tmp_path / "nested" / "alice.png"
    result = bot.execute_command("qrcode", "alice", str(output))
    assert result["status"] == "success"
    assert output.exists()


def test_bot_validates_input(tmp_path):
    bot = GhostOTPBot(storage_path=str(tmp_path / "secrets.enc"))
    assert bot.execute_command("generate", "")["status"] == "error"
    assert bot.execute_command("unknown")["status"] == "error"
