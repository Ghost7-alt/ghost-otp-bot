"""
Database manager for Ghost OTP Bot - SQLAlchemy integration
"""

from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, Session
from typing import Optional, List
from datetime import datetime
from .models import Base, User, OTPSecret, AuditLog
from .encryption import EncryptionManager


class DatabaseManager:
    """Manages database operations for Ghost OTP Bot"""
    
    def __init__(self, database_url: str = "sqlite:///ghost_otp_bot.db", 
                 encryption_manager: EncryptionManager = None):
        """
        Initialize database manager.
        
        Args:
            database_url: SQLAlchemy database URL
            encryption_manager: EncryptionManager instance for secret encryption
        """
        self.database_url = database_url
        self.engine = create_engine(database_url, echo=False)
        self.SessionLocal = sessionmaker(bind=self.engine)
        self.encryption_manager = encryption_manager or EncryptionManager()
    
    def init_database(self):
        """Initialize database - create all tables"""
        Base.metadata.create_all(self.engine)
        print("✓ Database initialized successfully")
    
    def get_session(self) -> Session:
        """Get a new database session"""
        return self.SessionLocal()
    
    # User operations
    def create_user(self, username: str, email: str = None, password_hash: str = None) -> User:
        """
        Create a new user.
        
        Args:
            username: Username
            email: Email address
            password_hash: Hashed password
            
        Returns:
            User object
        """
        session = self.get_session()
        try:
            user = User(username=username, email=email, password_hash=password_hash)
            session.add(user)
            session.commit()
            session.refresh(user)
            return user
        finally:
            session.close()
    
    def get_user(self, username: str) -> Optional[User]:
        """Get user by username"""
        session = self.get_session()
        try:
            return session.query(User).filter(User.username == username).first()
        finally:
            session.close()
    
    def get_user_by_id(self, user_id: int) -> Optional[User]:
        """Get user by ID"""
        session = self.get_session()
        try:
            return session.query(User).filter(User.id == user_id).first()
        finally:
            session.close()
    
    # OTP Secret operations
    def create_otp_secret(self, user_id: int, name: str, secret: str, 
                         issuer: str = "Ghost OTP Bot") -> OTPSecret:
        """
        Create and store an OTP secret (encrypted).
        
        Args:
            user_id: User ID
            name: Secret name (e.g., "Gmail")
            secret: Plain OTP secret to encrypt
            issuer: Issuer name
            
        Returns:
            OTPSecret object
        """
        session = self.get_session()
        try:
            encrypted_secret = self.encryption_manager.encrypt(secret)
            otp_secret = OTPSecret(
                user_id=user_id,
                name=name,
                encrypted_secret=encrypted_secret.encode(),
                issuer=issuer
            )
            session.add(otp_secret)
            session.commit()
            session.refresh(otp_secret)
            
            # Log the action
            self.log_audit("create_otp", "OTPSecret", otp_secret.id, "success", 
                          f"Created OTP secret: {name}")
            
            return otp_secret
        finally:
            session.close()
    
    def get_otp_secret(self, secret_id: int) -> Optional[OTPSecret]:
        """Get OTP secret by ID"""
        session = self.get_session()
        try:
            return session.query(OTPSecret).filter(OTPSecret.id == secret_id).first()
        finally:
            session.close()
    
    def get_user_otp_secrets(self, user_id: int) -> List[OTPSecret]:
        """Get all OTP secrets for a user"""
        session = self.get_session()
        try:
            return session.query(OTPSecret).filter(
                OTPSecret.user_id == user_id,
                OTPSecret.is_active == True
            ).all()
        finally:
            session.close()
    
    def decrypt_otp_secret(self, otp_secret: OTPSecret) -> str:
        """
        Decrypt an OTP secret.
        
        Args:
            otp_secret: OTPSecret object
            
        Returns:
            Decrypted secret string
        """
        encrypted_str = otp_secret.encrypted_secret.decode() if isinstance(otp_secret.encrypted_secret, bytes) else otp_secret.encrypted_secret
        return self.encryption_manager.decrypt(encrypted_str)
    
    def update_otp_secret(self, secret_id: int, name: str = None, 
                         secret: str = None) -> Optional[OTPSecret]:
        """
        Update an OTP secret.
        
        Args:
            secret_id: Secret ID
            name: New name (optional)
            secret: New secret to encrypt (optional)
            
        Returns:
            Updated OTPSecret object
        """
        session = self.get_session()
        try:
            otp_secret = session.query(OTPSecret).filter(OTPSecret.id == secret_id).first()
            if not otp_secret:
                return None
            
            if name:
                otp_secret.name = name
            if secret:
                otp_secret.encrypted_secret = self.encryption_manager.encrypt(secret).encode()
            
            otp_secret.updated_at = datetime.utcnow()
            session.commit()
            session.refresh(otp_secret)
            
            self.log_audit("update_otp", "OTPSecret", secret_id, "success", 
                          f"Updated OTP secret: {name or 'N/A'}")
            
            return otp_secret
        finally:
            session.close()
    
    def delete_otp_secret(self, secret_id: int) -> bool:
        """
        Delete an OTP secret (soft delete - marks as inactive).
        
        Args:
            secret_id: Secret ID
            
        Returns:
            True if deleted successfully
        """
        session = self.get_session()
        try:
            otp_secret = session.query(OTPSecret).filter(OTPSecret.id == secret_id).first()
            if not otp_secret:
                return False
            
            otp_secret.is_active = False
            otp_secret.updated_at = datetime.utcnow()
            session.commit()
            
            self.log_audit("delete_otp", "OTPSecret", secret_id, "success", 
                          "Deleted OTP secret")
            
            return True
        finally:
            session.close()
    
    def update_last_used(self, secret_id: int):
        """Update last_used timestamp for an OTP secret"""
        session = self.get_session()
        try:
            otp_secret = session.query(OTPSecret).filter(OTPSecret.id == secret_id).first()
            if otp_secret:
                otp_secret.last_used = datetime.utcnow()
                session.commit()
        finally:
            session.close()
    
    # Audit log operations
    def log_audit(self, action: str, resource: str = None, resource_id: int = None,
                 status: str = "success", details: str = None, user_id: int = None,
                 ip_address: str = None):
        """
        Log an audit event.
        
        Args:
            action: Action performed
            resource: Resource type
            resource_id: Resource ID
            status: Status (success/failure)
            details: Additional details
            user_id: User ID
            ip_address: IP address
        """
        session = self.get_session()
        try:
            audit_log = AuditLog(
                user_id=user_id,
                action=action,
                resource=resource,
                resource_id=resource_id,
                status=status,
                details=details,
                ip_address=ip_address
            )
            session.add(audit_log)
            session.commit()
        finally:
            session.close()
    
    def get_audit_logs(self, user_id: int = None, limit: int = 100) -> List[AuditLog]:
        """Get audit logs, optionally filtered by user"""
        session = self.get_session()
        try:
            query = session.query(AuditLog)
            if user_id:
                query = query.filter(AuditLog.user_id == user_id)
            return query.order_by(AuditLog.created_at.desc()).limit(limit).all()
        finally:
            session.close()
    
    def backup_user_secrets(self, user_id: int) -> dict:
        """
        Backup user's OTP secrets (encrypted).
        
        Args:
            user_id: User ID
            
        Returns:
            Dictionary with backup data
        """
        secrets = self.get_user_otp_secrets(user_id)
        backup = {
            "user_id": user_id,
            "created_at": datetime.utcnow().isoformat(),
            "secrets": [
                {
                    "name": s.name,
                    "issuer": s.issuer,
                    "encrypted_secret": s.encrypted_secret.decode() if isinstance(s.encrypted_secret, bytes) else s.encrypted_secret,
                    "created_at": s.created_at.isoformat()
                }
                for s in secrets
            ]
        }
        return backup
    
    def restore_user_secrets(self, user_id: int, backup: dict) -> bool:
        """
        Restore user's OTP secrets from backup.
        
        Args:
            user_id: User ID
            backup: Backup dictionary
            
        Returns:
            True if restoration successful
        """
        try:
            for secret_data in backup.get("secrets", []):
                self.create_otp_secret(
                    user_id=user_id,
                    name=secret_data["name"],
                    secret=self.encryption_manager.decrypt(secret_data["encrypted_secret"]),
                    issuer=secret_data.get("issuer", "Ghost OTP Bot")
                )
            return True
        except Exception as e:
            print(f"Restore failed: {str(e)}")
            return False
