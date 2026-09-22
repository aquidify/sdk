# Aquidify with curl

Any language that can send HTTPS + JSON can use Aquidify. These are the raw calls
every SDK in this repository makes. The contract is [`openapi.yaml`](../openapi.yaml).

```bash
export AQUIDIFY_API_KEY=...
```

## Interpret

```bash
curl -sS https://api.aquidify.com/v1/interpret \
  -H "Authorization: Bearer $AQUIDIFY_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "hiring.candidate",
    "input":  "Iščem delo v skladišču v Ljubljani, brez nočnih.",
    "locale": "sl-SI"
  }'
```

```json
{
  "request_id": "5f0c…",
  "domain": "hiring.candidate",
  "parser_version": "1.0.0",
  "schema_version": "1.0.0",
  "interpretation": {
    "intents": [{
      "roles":     [{ "value": "warehouse", "polarity": "include", "strength": "required", "condition": null, "raw_text": "delo v skladišču" }],
      "locations": [{ "value": "Ljubljana", "radius_km": null, "polarity": "include", "strength": "required", "condition": null, "raw_text": "v ljubljani" }],
      "schedules": [{ "value": "night", "polarity": "exclude", "strength": "required", "condition": null, "raw_text": "brez nočnih" }],
      "work_modes": [], "engagement_types": [], "salary": null, "availability": null,
      "capabilities": [], "interests": [], "constraints": [], "explicit_any": []
    }],
    "uncertainties": [],
    "contradictions": []
  },
  "clarification": null,
  "meta": { "source": "ai", "cache": "miss", "provider": "openai", "model": "…", "latency_ms": 1650 }
}
```

## Pin a version

Pinned requests keep returning the same shape when a newer parser ships.

```bash
-d '{"domain":"hiring.candidate","input":"…","locale":"sl","parser_version":"1.0.0","schema_version":"1.0.0"}'
```

## Retry safely

Send an `Idempotency-Key` and a retried request replays the first response
for 24 hours instead of being interpreted again.

```bash
-H "Idempotency-Key: 7d1c9a52-…"
```

## Errors

Every error has the same shape; branch on `error.code`, not on the message.

```json
{ "error": { "code": "invalid_request", "message": "…" }, "request_id": "…" }
```

| HTTP | `code` | Retry? |
|---|---|---|
| 400 | `bad_json` | no, fix the body |
| 401 | `unauthorized` | no, check the key |
| 413 | `body_too_large` | no (max 16 KB; input max 2000 characters) |
| 422 | `invalid_request`, `idempotency_mismatch` | no |
| 429 | `rate_limited` | yes, after `Retry-After` seconds |
| 502 | `interpretation_failed` | maybe, the model gave no valid answer |
| 503 | `model_unavailable` | yes, after `Retry-After` seconds |
| 504 | `timeout` | yes |
