"""
OTP Manager - Handles OTP generation, validation, and management
"""

import pyotp
import qrcode
from typing import Optional, Dict
import json


class OTPManager:
    """Manages OTP operations including generation, validation, and QR code creation."""
    
    def __init__(self):
        """Initialize OTP Manager."""
        self.secrets: Dict[str, str] = {}
        self.totp_objects: Dict[str, pyotp.TOTP] = {}
    
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
