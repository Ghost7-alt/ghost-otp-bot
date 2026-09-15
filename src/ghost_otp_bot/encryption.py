"""
Encryption module for Ghost OTP Bot - AES-256 encryption for secrets
"""

import os
import base64
from cryptography.fernet import Fernet
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2
from cryptography.hazmat.backends import default_backend


class EncryptionManager:
    """Manages encryption and decryption of OTP secrets"""
    
    def __init__(self, master_key: str = None, key_file: str = None):
        """
        Initialize encryption manager.
        
        Args:
            master_key: Master encryption key (32 chars minimum)
            key_file: Path to key file (if None, uses .ghost_key)
        """
        self.key_file = key_file or ".ghost_key"
        self.master_key = master_key
        self.cipher_suite = None
        self._initialize_cipher()
    
    def _initialize_cipher(self):
        """Initialize the cipher suite with master key"""
        key = self._get_or_create_key()
        self.cipher_suite = Fernet(key)
    
    def _get_or_create_key(self) -> bytes:
        """
        Get encryption key from file or create new one.
        
        Returns:
            Fernet key as bytes
        """
        # If master key provided, derive key from it
        if self.master_key:
            return self._derive_key_from_password(self.master_key)
        
        # Try to load existing key file
        if os.path.exists(self.key_file):
            with open(self.key_file, 'rb') as f:
                return f.read()
        
        # Create new key and save it
        key = Fernet.generate_key()
        self._save_key(key)
        return key
    
    def _derive_key_from_password(self, password: str) -> bytes:
        """
        Derive encryption key from password using PBKDF2.
        
        Args:
            password: Master password
            
        Returns:
            Fernet-compatible key
        """
        password_bytes = password.encode()
        salt = b'ghost_otp_bot'  # In production, use random salt
        
        kdf = PBKDF2(
            algorithm=hashes.SHA256(),
            length=32,
            salt=salt,
            iterations=100000,
            backend=default_backend()
        )
        
        key = base64.urlsafe_b64encode(kdf.derive(password_bytes))
        return key
    
    def _save_key(self, key: bytes):
        """
        Save encryption key to file.
        
        Args:
            key: Encryption key to save
        """
        os.makedirs(os.path.dirname(self.key_file) or '.', exist_ok=True)
        with open(self.key_file, 'wb') as f:
            f.write(key)
        # Restrict permissions to owner only
        os.chmod(self.key_file, 0o600)
    
    def encrypt(self, plaintext: str) -> str:
        """
        Encrypt plaintext string.
        
        Args:
            plaintext: String to encrypt
            
        Returns:
            Base64-encoded encrypted string
        """
        if not plaintext:
            return ""
        
        plaintext_bytes = plaintext.encode()
        encrypted_bytes = self.cipher_suite.encrypt(plaintext_bytes)
        encrypted_str = base64.b64encode(encrypted_bytes).decode()
        return encrypted_str
    
    def decrypt(self, encrypted_text: str) -> str:
        """
        Decrypt encrypted string.
        
        Args:
            encrypted_text: Base64-encoded encrypted string
            
        Returns:
            Decrypted plaintext string
        """
        if not encrypted_text:
            return ""
        
        try:
            encrypted_bytes = base64.b64decode(encrypted_text)
            plaintext_bytes = self.cipher_suite.decrypt(encrypted_bytes)
            plaintext = plaintext_bytes.decode()
            return plaintext
        except Exception as e:
            raise ValueError(f"Decryption failed: {str(e)}")
    
    def rotate_key(self, new_master_key: str) -> bool:
        """
        Rotate encryption key to a new one.
        
        Args:
            new_master_key: New master password
            
        Returns:
            True if rotation successful
        """
        try:
            # This would require re-encrypting all secrets
            # Implementation depends on database integration
            self.master_key = new_master_key
            self._initialize_cipher()
            return True
        except Exception as e:
            print(f"Key rotation failed: {str(e)}")
            return False
    
    def generate_recovery_codes(self, count: int = 10) -> list:
        """
        Generate recovery codes for account recovery.
        
        Args:
            count: Number of codes to generate
            
        Returns:
            List of recovery codes
        """
        recovery_codes = []
        for _ in range(count):
            # Generate 8-character alphanumeric codes
            code = base64.b32encode(os.urandom(5)).decode().rstrip('=')
            recovery_codes.append(code)
        return recovery_codes
    
    def encrypt_recovery_codes(self, recovery_codes: list) -> str:
        """
        Encrypt recovery codes for storage.
        
        Args:
            recovery_codes: List of recovery codes
            
        Returns:
            Encrypted recovery codes as string
        """
        import json
        codes_json = json.dumps(recovery_codes)
        return self.encrypt(codes_json)
    
    def decrypt_recovery_codes(self, encrypted_codes: str) -> list:
        """
        Decrypt recovery codes.
        
        Args:
            encrypted_codes: Encrypted recovery codes
            
        Returns:
            List of recovery codes
        """
        import json
        try:
            codes_json = self.decrypt(encrypted_codes)
            return json.loads(codes_json)
        except Exception as e:
            raise ValueError(f"Failed to decrypt recovery codes: {str(e)}")
