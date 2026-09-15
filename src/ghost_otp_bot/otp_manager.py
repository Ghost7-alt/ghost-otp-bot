"""
OTP Manager - Handles OTP generation, validation, and management with encryption
"""

import pyotp
import qrcode
from typing import Optional, Dict
import json
from .encryption import EncryptionManager


class OTPManager:
    """Manages OTP operations including generation, validation, and QR code creation"""
    
    def __init__(self, encryption_manager: EncryptionManager = None):
        """
        Initialize OTP Manager.
        
        Args:
            encryption_manager: EncryptionManager instance for encrypting secrets
        """
        self.secrets: Dict[str, str] = {}
        self.totp_objects: Dict[str, pyotp.TOTP] = {}
        self.encryption_manager = encryption_manager or EncryptionManager()
    
    def generate_secret(self, name: str, issuer: str = "Ghost OTP Bot") -> str:
        """
        Generate a new OTP secret for a user.
        
        Args:
            name: User identifier
            issuer: Issuer name for the OTP
            
        Returns:
            Base32 encoded secret
        """
        secret = pyotp.random_base32()
        self.secrets[name] = secret
        self.totp_objects[name] = pyotp.TOTP(secret)
        return secret
    
    def import_secret(self, name: str, secret: str):
        """
        Import an existing OTP secret.
        
        Args:
            name: User identifier
            secret: Base32 encoded secret
        """
        self.secrets[name] = secret
        self.totp_objects[name] = pyotp.TOTP(secret)
    
    def get_provisioning_uri(self, name: str, issuer: str = "Ghost OTP Bot") -> str:
        """
        Get the provisioning URI for QR code generation.
        
        Args:
            name: User identifier
            issuer: Issuer name
            
        Returns:
            Provisioning URI string
        """
        if name not in self.totp_objects:
            raise ValueError(f"No OTP secret found for user: {name}")
        
        return self.totp_objects[name].provisioning_uri(
            name=name,
            issuer_name=issuer
        )
    
    def generate_qr_code(self, name: str, issuer: str = "Ghost OTP Bot") -> bytes:
        """
        Generate a QR code image for OTP setup.
        
        Args:
            name: User identifier
            issuer: Issuer name
            
        Returns:
            QR code image as bytes
        """
        uri = self.get_provisioning_uri(name, issuer)
        qr = qrcode.QRCode()
        qr.add_data(uri)
        qr.make()
        
        img = qr.make_image()
        
        # Return image as bytes
        from io import BytesIO
        img_bytes = BytesIO()
        img.save(img_bytes, format='PNG')
        return img_bytes.getvalue()
    
    def verify_token(self, name: str, token: str) -> bool:
        """
        Verify an OTP token for a user.
        
        Args:
            name: User identifier
            token: OTP token to verify
            
        Returns:
            True if token is valid, False otherwise
        """
        if name not in self.totp_objects:
            return False
        
        try:
            return self.totp_objects[name].verify(token)
        except Exception:
            return False
    
    def get_current_token(self, name: str) -> str:
        """
        Get the current OTP token for a user.
        
        Args:
            name: User identifier
            
        Returns:
            Current OTP token
        """
        if name not in self.totp_objects:
            raise ValueError(f"No OTP secret found for user: {name}")
        
        return self.totp_objects[name].now()
    
    def load_secrets(self, secrets_dict: Dict[str, str]):
        """
        Load secrets from a dictionary.
        
        Args:
            secrets_dict: Dictionary mapping user names to secrets
        """
        self.secrets.update(secrets_dict)
        for name, secret in secrets_dict.items():
            self.totp_objects[name] = pyotp.TOTP(secret)
    
    def save_secrets(self) -> Dict[str, str]:
        """
        Export secrets for backup/storage.
        
        Returns:
            Dictionary of user names to secrets
        """
        return self.secrets.copy()
    
    def get_encrypted_secret(self, name: str) -> str:
        """
        Get encrypted version of a secret.
        
        Args:
            name: User identifier
            
        Returns:
            Encrypted secret string
        """
        if name not in self.secrets:
            raise ValueError(f"No OTP secret found for user: {name}")
        
        return self.encryption_manager.encrypt(self.secrets[name])
    
    def import_encrypted_secret(self, name: str, encrypted_secret: str, issuer: str = "Ghost OTP Bot"):
        """
        Import an encrypted OTP secret.
        
        Args:
            name: User identifier
            encrypted_secret: Encrypted secret string
            issuer: Issuer name
        """
        decrypted_secret = self.encryption_manager.decrypt(encrypted_secret)
        self.import_secret(name, decrypted_secret)
    
    def generate_recovery_codes(self, name: str, count: int = 10) -> list:
        """
        Generate recovery codes for a user.
        
        Args:
            name: User identifier
            count: Number of codes to generate
            
        Returns:
            List of recovery codes
        """
        recovery_codes = self.encryption_manager.generate_recovery_codes(count)
        return recovery_codes
    
    def get_encrypted_recovery_codes(self, name: str, recovery_codes: list) -> str:
        """
        Get encrypted recovery codes for storage.
        
        Args:
            name: User identifier
            recovery_codes: List of recovery codes
            
        Returns:
            Encrypted recovery codes string
        """
        return self.encryption_manager.encrypt_recovery_codes(recovery_codes)
    
    def verify_recovery_code(self, name: str, code: str, encrypted_codes: str) -> bool:
        """
        Verify a recovery code and remove it from the list.
        
        Args:
            name: User identifier
            code: Recovery code to verify
            encrypted_codes: Encrypted recovery codes
            
        Returns:
            True if code is valid
        """
        try:
            codes = self.encryption_manager.decrypt_recovery_codes(encrypted_codes)
            if code in codes:
                codes.remove(code)
                return True
            return False
        except Exception:
            return False
    
    def remove_secret(self, name: str):
        """
        Remove a secret for a user.
        
        Args:
            name: User identifier
        """
        if name in self.secrets:
            del self.secrets[name]
        if name in self.totp_objects:
            del self.totp_objects[name]
