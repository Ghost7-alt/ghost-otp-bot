"""
Main entry point for Ghost OTP Bot
"""

import sys
import os
from bot import GhostOTPBot


def main():
    """Main function to run the bot."""
    print("🤖 Ghost OTP Bot - Starting...")
    
    # Initialize bot
    bot = GhostOTPBot()
    
    # Try to initialize Automaton
    print("\n📡 Initializing Automaton...")
    if not bot.initialize_automaton():
        print("\n⚠️  Warning: Automaton not available")
        print("   The bot will work without Automaton, but won't have two-way communication")
    
    print("\n✓ Bot ready!")
    print("Type 'help' for available commands\n")
    
    # Interactive mode
    try:
        while True:
            user_input = input("ghost-otp> ").strip()
            
            if not user_input:
                continue
            
            if user_input.lower() == 'exit':
                print("Goodbye!")
                break
            
            if user_input.lower() == 'help':
                result = bot.execute_command('help')
                print(f"\nAvailable commands:")
                for cmd, desc in result['commands'].items():
                    print(f"  {cmd}: {desc}")
                print()
                continue
            
            # Parse simple commands: "command arg1 arg2"
            parts = user_input.split()
            command = parts[0]
            args = parts[1:] if len(parts) > 1 else []
            
            result = bot.execute_command(command, *args)
            
            print(f"\nResult: {result['status']}")
            print(f"Message: {result.get('message', result)}\n")
    
    except KeyboardInterrupt:
        print("\n\nShutdown requested...")


if __name__ == "__main__":
    main()
