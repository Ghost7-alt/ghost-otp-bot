import pyotp

from ghost_otp_bot import OTPManager


def test_generate_verify_and_reload(tmp_path):
    path = tmp_path / "secrets.enc"
    key = __import__("cryptography.fernet", fromlist=["Fernet"]).Fernet.generate_key()
    manager = OTPManager(str(path), key)
    manager.generate_secret("alice")
    token = manager.get_current_token("alice")
    assert manager.verify_token("alice", token)

    reloaded = OTPManager(str(path), key)
    assert reloaded.verify_token("alice", token)


def test_qr_code_is_png(tmp_path):
    from cryptography.fernet import Fernet
    manager = OTPManager(str(tmp_path / "secrets.enc"), Fernet.generate_key())
    manager.generate_secret("bob")
    assert manager.generate_qr_code("bob").startswith(b"\x89PNG\r\n\x1a\n")


def test_invalid_token_and_missing_user(tmp_path):
    from cryptography.fernet import Fernet
    manager = OTPManager(str(tmp_path / "secrets.enc"), Fernet.generate_key())
    assert not manager.verify_token("missing", "123456")
    manager.generate_secret("alice")
    assert not manager.verify_token("alice", "not-a-token")
