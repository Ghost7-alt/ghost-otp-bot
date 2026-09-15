"""
Database models for Ghost OTP Bot
"""

from datetime import datetime
from sqlalchemy import Column, String, DateTime, Integer, Boolean, LargeBinary, ForeignKey
from sqlalchemy.ext.declarative import declarative_base
from sqlalchemy.orm import relationship

Base = declarative_base()


class User(Base):
    """User model for Ghost OTP Bot"""
    __tablename__ = 'users'
    
    id = Column(Integer, primary_key=True)
    username = Column(String(255), unique=True, nullable=False, index=True)
    email = Column(String(255), unique=True, nullable=True)
    password_hash = Column(String(255), nullable=True)
    is_active = Column(Boolean, default=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    
    # Relationships
    otp_secrets = relationship("OTPSecret", back_populates="user", cascade="all, delete-orphan")
    audit_logs = relationship("AuditLog", back_populates="user", cascade="all, delete-orphan")
    
    def __repr__(self):
        return f"<User(id={self.id}, username={self.username})>"


class OTPSecret(Base):
    """OTP Secret model - stores encrypted OTP secrets"""
    __tablename__ = 'otp_secrets'
    
    id = Column(Integer, primary_key=True)
    user_id = Column(Integer, ForeignKey('users.id'), nullable=False, index=True)
    name = Column(String(255), nullable=False)  # e.g., "Gmail", "GitHub"
    encrypted_secret = Column(LargeBinary, nullable=False)
    issuer = Column(String(255), default="Ghost OTP Bot")
    is_active = Column(Boolean, default=True)
    backup_codes = Column(LargeBinary, nullable=True)  # Encrypted backup codes
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    last_used = Column(DateTime, nullable=True)
    
    # Relationships
    user = relationship("User", back_populates="otp_secrets")
    
    def __repr__(self):
        return f"<OTPSecret(id={self.id}, user_id={self.user_id}, name={self.name})>"


class AuditLog(Base):
    """Audit log model - tracks all OTP operations"""
    __tablename__ = 'audit_logs'
    
    id = Column(Integer, primary_key=True)
    user_id = Column(Integer, ForeignKey('users.id'), nullable=True, index=True)
    action = Column(String(255), nullable=False)  # generate, verify, qrcode, etc.
    resource = Column(String(255), nullable=True)  # User, OTPSecret, etc.
    resource_id = Column(Integer, nullable=True)
    status = Column(String(50), nullable=False)  # success, failure
    details = Column(String(1024), nullable=True)
    ip_address = Column(String(45), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, index=True)
    
    # Relationships
    user = relationship("User", back_populates="audit_logs")
    
    def __repr__(self):
        return f"<AuditLog(id={self.id}, action={self.action}, status={self.status})>"
