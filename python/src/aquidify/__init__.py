"""Aquidify API client for Python. Standard library only.

    from aquidify import Aquidify
    aq = Aquidify()  # reads AQUIDIFY_API_KEY
    r = aq.interpret("hiring.candidate", "Iščem delo v skladišču v Ljubljani, brez nočnih.", "sl-SI")
    r["interpretation"]["intents"][0]["roles"][0]["value"]  # "warehouse"
"""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from typing import Any, Optional

__all__ = ["Aquidify", "AquidifyError", "__version__"]
__version__ = "0.1.0"

_RETRYABLE = {"rate_limited", "model_unavailable", "timeout", "network"}


class AquidifyError(Exception):
    """A failed call. `code` is the API's stable error code, or "network"."""

    def __init__(self, message: str, status: int, code: str,
                 request_id: Optional[str] = None, retry_after: Optional[int] = None):
        super().__init__(message)
        self.status = status
        self.code = code
        self.request_id = request_id
        self.retry_after = retry_after

    @property
    def retryable(self) -> bool:
        return self.code in _RETRYABLE


class Aquidify:
    def __init__(self, api_key: Optional[str] = None,
                 base_url: str = "https://api.aquidify.com", timeout: float = 60):
        self.api_key = api_key or os.environ.get("AQUIDIFY_API_KEY", "")
        if not self.api_key:
            raise ValueError("Aquidify API key missing: pass api_key or set AQUIDIFY_API_KEY.")
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout

    def interpret(self, domain: str, input: str, locale: str, *,
                  parser_version: Optional[str] = None, schema_version: Optional[str] = None,
                  idempotency_key: Optional[str] = None) -> dict[str, Any]:
        """Interpret free text into structured intent. Raises AquidifyError on failure."""
        body: dict[str, Any] = {"domain": domain, "input": input, "locale": locale}
        if parser_version:
            body["parser_version"] = parser_version
        if schema_version:
            body["schema_version"] = schema_version
        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": f"aquidify-sdk-python/{__version__}",
        }
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key

        req = urllib.request.Request(f"{self.base_url}/v1/interpret",
                                     data=json.dumps(body).encode(), headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as res:
                return json.load(res)
        except urllib.error.HTTPError as e:
            try:
                data = json.load(e)
            except ValueError:
                data = {}
            err = data.get("error") or {}
            retry_after = e.headers.get("Retry-After")
            raise AquidifyError(
                err.get("message", f"Aquidify returned HTTP {e.code}"),
                e.code,
                err.get("code", "unknown"),
                data.get("request_id") or e.headers.get("X-Request-ID"),
                int(retry_after) if retry_after and retry_after.isdigit() else None,
            ) from None
        except (urllib.error.URLError, TimeoutError) as e:
            raise AquidifyError(f"Aquidify request failed: {e}", 0, "network") from None
