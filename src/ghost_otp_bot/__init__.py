"""
Ghost OTP Bot - Two-way communication OTP authentication bot using Automaton
"""

__version__ = "0.1.0"
__author__ = "Ghost7-alt"

from .bot import GhostOTPBot
from .otp_manager import OTPManager

__all__ = ["GhostOTPBot", "OTPManager"]
