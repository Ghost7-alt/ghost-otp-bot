"""OTP Manager with encrypted at-rest storage."""

from __future__ import annotations

import base64
import json
import os
import secrets
from io import BytesIO
from pathlib import Path
from typing import Dict, Optional

import pyotp
import qrcode
from cryptography.fernet import Fernet, InvalidToken


class OTPStorageError(RuntimeError):
    """Raised when encrypted OTP storage cannot be read or written."""


class OTPManager:
    """Manage TOTP credentials and encrypt them before writing to disk.

    The encryption key is read from ``GHOST_OTP_STORAGE_KEY`` when present.
    Otherwise a key is created in ``~/.ghost_otp_bot/master.key`` with private
    permissions. For server deployments, set the environment variable instead.
    """

    def __init__(
        self,
        storage_path: Optional[str] = None,
        encryption_key: Optional[str | bytes] = None,
    ) -> None:
        self.secrets: Dict[str, str] = {}
        self.totp_objects: Dict[str, pyotp.TOTP] = {}
        self.storage_path = Path(storage_path or Path.home() / ".ghost_otp_bot" / "secrets.enc")
        self.storage_path.parent.mkdir(parents=True, exist_ok=True)
        self._fernet = Fernet(self._get_key(encryption_key))
        self._load_from_disk()

    def _get_key(self, supplied: Optional[str | bytes]) -> bytes:
        if supplied:
            key = supplied.encode() if isinstance(supplied, str) else supplied
        elif os.getenv("GHOST_OTP_STORAGE_KEY"):
            key = os.environ["GHOST_OTP_STORAGE_KEY"].encode()
        else:
            key_path = self.storage_path.parent / "master.key"
            if key_path.exists():
                key = key_path.read_bytes().strip()
            else:
                key = Fernet.generate_key()
                key_path.write_bytes(key + b"\n")
                try:
                    key_path.chmod(0o600)
                except OSError:
                    pass

        try:
            Fernet(key)
        except (TypeError, ValueError):
            raise ValueError(
                "GHOST_OTP_STORAGE_KEY must be a valid Fernet key; generate one with "
                "python -c \"from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())\""
            ) from None
        return key

    def _load_from_disk(self) -> None:
        if not self.storage_path.exists():
            return
        try:
            encrypted = self.storage_path.read_bytes()
            data = json.loads(self._fernet.decrypt(encrypted).decode("utf-8"))
            self._set_secrets(data)
        except (InvalidToken, json.JSONDecodeError, OSError, TypeError, ValueError) as exc:
            raise OTPStorageError(
                f"Unable to decrypt OTP storage at {self.storage_path}; check the encryption key"
            ) from exc

    def _set_secrets(self, secrets_dict: Dict[str, str]) -> None:
        self.secrets = {}
        self.totp_objects = {}
        for name, secret in secrets_dict.items():
            username = str(name).strip()
            if not username or not isinstance(secret, str):
                continue
            try:
                self.totp_objects[username] = pyotp.TOTP(secret)
            except (TypeError, ValueError):
                continue
            self.secrets[username] = secret

    def generate_secret(self, name: str, issuer: str = "Ghost OTP Bot") -> str:
        del issuer  # Reserved for API compatibility; URI generation receives it separately.
        username = self._require_name(name)
        secret = pyotp.random_base32()
        self.secrets[username] = secret
        self.totp_objects[username] = pyotp.TOTP(secret)
        self.save_secrets()
        return secret

    def get_provisioning_uri(self, name: str, issuer: str = "Ghost OTP Bot") -> str:
        username = self._require_name(name)
        if username not in self.totp_objects:
            raise ValueError(f"No OTP secret found for user: {username}")
        if not issuer or not issuer.strip():
            raise ValueError("Issuer is required")
        return self.totp_objects[username].provisioning_uri(
            name=username, issuer_name=issuer.strip()
        )

    def generate_qr_code(self, name: str, issuer: str = "Ghost OTP Bot") -> bytes:
        qr = qrcode.QRCode(
            error_correction=qrcode.constants.ERROR_CORRECT_M,
            box_size=10,
            border=4,
        )
        qr.add_data(self.get_provisioning_uri(name, issuer))
        qr.make(fit=True)
        image = qr.make_image(fill_color="black", back_color="white")
        output = BytesIO()
        image.save(output, format="PNG")
        return output.getvalue()

    def verify_token(self, name: str, token: str) -> bool:
        if not name or not token:
            return False
        username = str(name).strip()
        if username not in self.totp_objects:
            return False
        try:
            return self.totp_objects[username].verify(str(token).strip())
        except (TypeError, ValueError):
            return False

    def get_current_token(self, name: str) -> str:
        username = self._require_name(name)
        if username not in self.totp_objects:
            raise ValueError(f"No OTP secret found for user: {username}")
        return self.totp_objects[username].now()

    def load_secrets(self, secrets_dict: Dict[str, str]) -> None:
        if not isinstance(secrets_dict, dict):
            raise ValueError("Secrets must be a dictionary")
        self._set_secrets(secrets_dict)
        self.save_secrets()

    def save_secrets(self) -> Dict[str, str]:
        payload = json.dumps(self.secrets, sort_keys=True).encode("utf-8")
        temporary = self.storage_path.with_name(f".{self.storage_path.name}.{secrets.token_hex(8)}.tmp")
        try:
            temporary.write_bytes(self._fernet.encrypt(payload))
            try:
                temporary.chmod(0o600)
            except OSError:
                pass
            temporary.replace(self.storage_path)
        finally:
            temporary.unlink(missing_ok=True)
        return self.secrets.copy()

    @staticmethod
    def _require_name(name: str) -> str:
        username = str(name).strip() if name is not None else ""
        if not username:
            raise ValueError("Username is required")
        return username
