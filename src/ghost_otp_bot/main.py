"""Simple interactive CLI for Ghost OTP Bot."""

import json

from .bot import GhostOTPBot


def main() -> None:
    bot = GhostOTPBot()
    print("🤖 Ghost OTP Bot - Starting...")
    if not bot.initialize_automaton():
        print("⚠️  Automaton unavailable; continuing without it")
    print("Type 'help' for available commands; type 'exit' to quit.\n")
    try:
        while True:
            user_input = input("ghost-otp> ").strip()
            if not user_input:
                continue
            if user_input.lower() in {"exit", "quit"}:
                print("Goodbye!")
                break
            if user_input.lower() == "help":
                result = bot.execute_command("help")
                for command, description in result["commands"].items():
                    print(f"  {command}: {description}")
                continue
            parts = user_input.split()
            print(json.dumps(bot.execute_command(parts[0], *parts[1:]), indent=2, default=str))
    except (KeyboardInterrupt, EOFError):
        print("\nShutdown requested...")


if __name__ == "__main__":
    main()
