"""Ghost OTP Bot command interface."""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Callable, Optional

from .otp_manager import OTPManager


class GhostOTPBot:
    """Expose OTP operations as commands and optional event callbacks."""

    def __init__(self, automaton_path: Optional[str] = None, storage_path: Optional[str] = None) -> None:
        self.otp_manager = OTPManager(storage_path=storage_path)
        base_dir = Path(__file__).resolve().parents[2]
        self.automaton_path = Path(automaton_path) if automaton_path else base_dir / "automaton"
        self.automaton = None
        self.listeners: dict[str, list[Callable]] = {}
        self.commands = {
            "generate": self.cmd_generate_otp,
            "verify": self.cmd_verify_otp,
            "qrcode": self.cmd_generate_qrcode,
            "help": self.cmd_help,
            "current": self.cmd_current_token,
        }

    def initialize_automaton(self) -> bool:
        try:
            if not self.automaton_path.exists():
                raise ImportError(f"Automaton not found at {self.automaton_path}")
            sys.path.insert(0, str(self.automaton_path.parent))
            import automaton as automaton_module
            self.automaton = automaton_module
            return True
        except ImportError:
            return False

    def register_listener(self, event_type: str, callback: Callable) -> None:
        self.listeners.setdefault(event_type, []).append(callback)

    def emit_event(self, event_type: str, data: dict) -> None:
        for callback in self.listeners.get(event_type, []):
            try:
                callback(data)
            except Exception:
                continue

    def cmd_generate_otp(self, username: str, issuer: str = "Ghost OTP Bot") -> dict:
        if not username or not str(username).strip():
            return {"status": "error", "message": "Username is required"}
        username = str(username).strip()
        secret = self.otp_manager.generate_secret(username, issuer)
        uri = self.otp_manager.get_provisioning_uri(username, issuer)
        self.emit_event("otp_generated", {"username": username, "secret": secret, "uri": uri})
        return {"status": "success", "secret": secret, "uri": uri, "message": f"OTP secret generated for {username}"}

    def cmd_verify_otp(self, username: str, token: str) -> dict:
        if not username or not str(username).strip():
            return {"status": "error", "message": "Username is required"}
        if not token or not str(token).strip():
            return {"status": "error", "message": "Token is required"}
        username, token = str(username).strip(), str(token).strip()
        valid = self.otp_manager.verify_token(username, token)
        self.emit_event("otp_verification", {"username": username, "valid": valid})
        return {"status": "success" if valid else "failed", "valid": valid, "message": f"Token {'valid' if valid else 'invalid'} for {username}"}

    def cmd_generate_qrcode(self, username: str, output_path: Optional[str] = None) -> dict:
        if not username or not str(username).strip():
            return {"status": "error", "message": "Username is required"}
        try:
            username = str(username).strip()
            qr_bytes = self.otp_manager.generate_qr_code(username)
            if output_path:
                output = Path(output_path).expanduser()
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_bytes(qr_bytes)
            self.emit_event("qrcode_generated", {"username": username, "output_path": output_path})
            return {"status": "success", "qr_code": qr_bytes, "output_path": output_path, "message": f"QR code generated for {username}"}
        except (OSError, ValueError) as exc:
            return {"status": "error", "message": str(exc)}

    def cmd_current_token(self, username: str) -> dict:
        try:
            username = str(username).strip()
            return {"status": "success", "token": self.otp_manager.get_current_token(username), "username": username}
        except (TypeError, ValueError) as exc:
            return {"status": "error", "message": str(exc)}

    def cmd_help(self) -> dict:
        return {"status": "success", "commands": {"generate": "Generate new OTP secret for a user", "verify": "Verify an OTP token", "qrcode": "Generate QR code for OTP setup", "current": "Get current OTP token", "help": "Show this help message"}}

    def execute_command(self, command: str, *args, **kwargs) -> dict:
        if command not in self.commands:
            return {"status": "error", "message": f"Unknown command: {command}"}
        try:
            return self.commands[command](*args, **kwargs)
        except (TypeError, ValueError, OSError) as exc:
            return {"status": "error", "message": f"Error executing {command}: {exc}"}
