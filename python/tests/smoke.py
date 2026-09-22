"""Live smoke test, no model calls (free):
    AQUIDIFY_API_KEY=... [AQUIDIFY_BASE_URL=...] uv run python tests/smoke.py
"""
import os
import sys

from aquidify import Aquidify, AquidifyError

if not os.environ.get("AQUIDIFY_API_KEY"):
    print("skip: set AQUIDIFY_API_KEY")
    sys.exit(0)
base = os.environ.get("AQUIDIFY_BASE_URL", "https://api.aquidify.com")


def check(ok: bool, what: str) -> None:
    if not ok:
        print(f"FAIL: {what}", file=sys.stderr)
        sys.exit(1)


r = Aquidify(base_url=base).interpret("hiring.candidate", "Ljubljana", "sl-SI")
check(r["meta"]["source"] == "deterministic", "place alone resolves without a model")
check(r["interpretation"]["intents"][0]["locations"][0]["value"] == "Ljubljana", "location value")
check(r["clarification"] is None, "no clarification")

for key, domain, status, code in [("wrong-key", "hiring.candidate", 401, "unauthorized"),
                                  (None, "no.such.domain", 422, "invalid_request")]:
    try:
        Aquidify(api_key=key, base_url=base).interpret(domain, "x", "sl")
        check(False, f"{code} must raise")
    except AquidifyError as e:
        check(e.status == status and e.code == code and e.request_id and not e.retryable, f"{status} {code}")

print("python: ok")
