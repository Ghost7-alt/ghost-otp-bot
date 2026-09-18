"""Shared pytest fixtures."""

import pytest
from cryptography.fernet import Fernet


@pytest.fixture
def encryption_key():
    return Fernet.generate_key()
