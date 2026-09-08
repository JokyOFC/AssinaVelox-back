"""Error hierarchy shared by every command.

Exit-code contract (must stay in sync with README.md):
    0 - success
    2 - usage error (bad arguments, missing environment variable, bad plan)
    3 - processing error (unexpected failure while working on valid input)
    4 - input rejected (invalid / encrypted / unsupported / missing input)
"""

from __future__ import annotations

EXIT_OK = 0
EXIT_USAGE = 2
EXIT_PROCESSING = 3
EXIT_INPUT_REJECTED = 4


class PdfToolError(Exception):
    """Base class. ``code`` is a stable snake_case identifier for Laravel."""

    exit_code = EXIT_PROCESSING

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message

    def to_json(self) -> dict:
        # Messages must never contain secrets (passphrases, key material).
        return {"ok": False, "error": {"code": self.code, "message": self.message}}


class UsageError(PdfToolError):
    """Bad CLI usage: wrong arguments, missing env var, malformed plan."""

    exit_code = EXIT_USAGE


class ProcessingError(PdfToolError):
    """Unexpected failure while processing otherwise acceptable input."""

    exit_code = EXIT_PROCESSING


class InputRejected(PdfToolError):
    """The input cannot be handled: corrupt, encrypted, unsupported, missing."""

    exit_code = EXIT_INPUT_REJECTED
