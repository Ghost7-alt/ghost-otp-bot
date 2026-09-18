"""Client for NVIDIA's OpenAI-compatible chat completions API."""

import os
from typing import Any, Dict, List, Optional

import requests
from dotenv import load_dotenv


load_dotenv()


class NVIDIAAPIError(RuntimeError):
    """Raised when the NVIDIA API cannot fulfill a chat request."""


class NVIDIAClient:
    """Small, configurable client for NVIDIA chat completions."""

    DEFAULT_ENDPOINT = "https://integrate.api.nvidia.com/v1/chat/completions"
    DEFAULT_MODEL = "nvidia/nemotron-3-nano-omni-30b-a3b-reasoning"

    def __init__(
        self,
        api_key: Optional[str] = None,
        endpoint: Optional[str] = None,
        model: Optional[str] = None,
        timeout: Optional[float] = None,
    ):
        self.api_key = api_key or os.getenv("NVIDIA_API_KEY")
        self.endpoint = endpoint or os.getenv(
            "NVIDIA_API_ENDPOINT", self.DEFAULT_ENDPOINT
        )
        self.model = model or os.getenv("NVIDIA_MODEL", self.DEFAULT_MODEL)
        self.timeout = timeout or float(os.getenv("NVIDIA_TIMEOUT", "60"))

    def chat(
        self,
        messages: List[Dict[str, str]],
        max_tokens: int = 65536,
        reasoning_budget: int = 16384,
        temperature: float = 0.6,
        top_p: float = 0.95,
    ) -> str:
        """Send chat messages and return the assistant's text response."""
        if not self.api_key:
            raise NVIDIAAPIError(
                "NVIDIA_API_KEY is not configured. Add it to your .env file."
            )
        if not messages:
            raise ValueError("At least one chat message is required")

        payload: Dict[str, Any] = {
            "messages": messages,
            "model": self.model,
            "max_tokens": max_tokens,
            "reasoning_budget": reasoning_budget,
            "stream": False,
            "temperature": temperature,
            "top_p": top_p,
        }

        try:
            response = requests.post(
                self.endpoint,
                headers={
                    "Authorization": f"Bearer {self.api_key}",
                    "Accept": "application/json",
                },
                json=payload,
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            raise NVIDIAAPIError(f"Unable to reach NVIDIA API: {exc}") from exc

        if not response.ok:
            detail = response.text.strip() or "No error details returned"
            raise NVIDIAAPIError(
                f"NVIDIA API returned HTTP {response.status_code}: {detail}"
            )

        try:
            data = response.json()
            return data["choices"][0]["message"]["content"]
        except (ValueError, KeyError, IndexError, TypeError) as exc:
            raise NVIDIAAPIError(
                "NVIDIA API returned an unexpected response format"
            ) from exc
