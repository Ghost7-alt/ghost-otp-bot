"""
Ghost OTP Bot - Main bot class integrating Automaton for two-way communication
"""

import sys
import os
from typing import Callable, Optional
from .otp_manager import OTPManager
from .nvidia_client import NVIDIAAPIError, NVIDIAClient


class GhostOTPBot:
    """
    Main OTP Bot class that integrates Automaton for two-way communication.
    """
    
    def __init__(self, automaton_path: str = None, nvidia_client: NVIDIAClient = None):
        """
        Initialize Ghost OTP Bot.
        
        Args:
            automaton_path: Path to automaton module (defaults to project automaton submodule)
        """
        self.otp_manager = OTPManager()
        self.nvidia_client = nvidia_client or NVIDIAClient()
        self.automaton_path = automaton_path or os.path.join(
            os.path.dirname(__file__), '../../automaton'
        )
        self.automaton = None
        self.listeners = {}
        self.commands = {}
        self._setup_commands()
    
    def _setup_commands(self):
        """Set up default commands for the bot."""
        self.commands = {
            'generate': self.cmd_generate_otp,
            'verify': self.cmd_verify_otp,
            'qrcode': self.cmd_generate_qrcode,
            'help': self.cmd_help,
            'current': self.cmd_current_token,
            'chat': self.cmd_chat,
        }
    
    def initialize_automaton(self):
        """
        Initialize Automaton communication interface.
        This should be called after Automaton submodule is properly set up.
        """
        try:
            sys.path.insert(0, os.path.dirname(self.automaton_path))
            # Import automaton module
            import automaton as automaton_module
            self.automaton = automaton_module
            print("✓ Automaton initialized successfully")
            return True
        except ImportError as e:
            print(f"✗ Failed to initialize Automaton: {e}")
            print("  Make sure to run: git submodule update --init --recursive")
            return False
    
    def register_listener(self, event_type: str, callback: Callable):
        """
        Register a listener for Automaton events.
        
        Args:
            event_type: Type of event to listen for
            callback: Function to call when event occurs
        """
        if event_type not in self.listeners:
            self.listeners[event_type] = []
        self.listeners[event_type].append(callback)
    
    def emit_event(self, event_type: str, data: dict):
        """
        Emit an event to all registered listeners.
        
        Args:
            event_type: Type of event
            data: Event data
        """
        if event_type in self.listeners:
            for callback in self.listeners[event_type]:
                try:
                    callback(data)
                except Exception as e:
                    print(f"Error in listener for {event_type}: {e}")
    
    # Command handlers
    def cmd_generate_otp(self, username: str, issuer: str = "Ghost OTP Bot") -> dict:
        """
        Generate a new OTP secret for a user.
        
        Args:
            username: Username to generate OTP for
            issuer: Issuer name
            
        Returns:
            Dictionary with secret and provisioning URI
        """
        secret = self.otp_manager.generate_secret(username, issuer)
        uri = self.otp_manager.get_provisioning_uri(username, issuer)
        
        self.emit_event('otp_generated', {
            'username': username,
            'secret': secret,
            'uri': uri
        })
        
        return {
            'status': 'success',
            'secret': secret,
            'uri': uri,
            'message': f"OTP secret generated for {username}"
        }
    
    def cmd_verify_otp(self, username: str, token: str) -> dict:
        """
        Verify an OTP token for a user.
        
        Args:
            username: Username to verify
            token: OTP token
            
        Returns:
            Dictionary with verification result
        """
        is_valid = self.otp_manager.verify_token(username, token)
        
        self.emit_event('otp_verification', {
            'username': username,
            'valid': is_valid
        })
        
        return {
            'status': 'success' if is_valid else 'failed',
            'valid': is_valid,
            'message': f"Token {'valid' if is_valid else 'invalid'} for {username}"
        }
    
    def cmd_generate_qrcode(self, username: str, output_path: str = None) -> dict:
        """
        Generate a QR code for OTP setup.
        
        Args:
            username: Username to generate QR code for
            output_path: Optional path to save QR code
            
        Returns:
            Dictionary with QR code data
        """
        try:
            qr_bytes = self.otp_manager.generate_qr_code(username)
            
            if output_path:
                with open(output_path, 'wb') as f:
                    f.write(qr_bytes)
            
            self.emit_event('qrcode_generated', {
                'username': username,
                'output_path': output_path
            })
            
            return {
                'status': 'success',
                'qr_code': qr_bytes,
                'output_path': output_path,
                'message': f"QR code generated for {username}"
            }
        except Exception as e:
            return {
                'status': 'error',
                'message': str(e)
            }
    
    def cmd_current_token(self, username: str) -> dict:
        """
        Get the current OTP token for a user.
        
        Args:
            username: Username
            
        Returns:
            Dictionary with current token
        """
        try:
            token = self.otp_manager.get_current_token(username)
            return {
                'status': 'success',
                'token': token,
                'username': username
            }
        except Exception as e:
            return {
                'status': 'error',
                'message': str(e)
            }
    
    def cmd_help(self) -> dict:
        """Get help information about available commands."""
        return {
            'status': 'success',
            'commands': {
                'generate': 'Generate new OTP secret for a user',
                'verify': 'Verify an OTP token',
                'qrcode': 'Generate QR code for OTP setup',
                'current': 'Get current OTP token',
                'chat': 'Ask the configured NVIDIA model a question',
                'help': 'Show this help message'
            }
        }

    def cmd_chat(self, prompt: str) -> dict:
        """Send a prompt to the configured NVIDIA chat model."""
        try:
            response = self.nvidia_client.chat([
                {'role': 'user', 'content': prompt}
            ])
            return {
                'status': 'success',
                'response': response,
                'message': response,
            }
        except (NVIDIAAPIError, ValueError) as exc:
            return {
                'status': 'error',
                'message': str(exc),
            }
    
    def execute_command(self, command: str, *args, **kwargs) -> dict:
        """
        Execute a bot command.
        
        Args:
            command: Command name
            *args: Positional arguments
            **kwargs: Keyword arguments
            
        Returns:
            Command result
        """
        if command not in self.commands:
            return {
                'status': 'error',
                'message': f"Unknown command: {command}"
            }
        
        try:
            return self.commands[command](*args, **kwargs)
        except Exception as e:
            return {
                'status': 'error',
                'message': f"Error executing {command}: {str(e)}"
            }
