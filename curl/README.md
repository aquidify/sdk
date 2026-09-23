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

## Your own task (any industry)

Register what you want pulled out of free text: instructions, a JSON Schema of
the fields and, optionally, up to 5 examples. The task is private to your API key.

```bash
curl -sS -X PUT https://api.aquidify.com/v1/tasks/support.ticket@1.0.0 \
  -H "Authorization: Bearer $AQUIDIFY_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "instructions": "Classify customer support emails for routing.",
    "schema": {
      "type": "object",
      "properties": {
        "category": { "enum": ["billing", "bug", "refund", "other"] },
        "order_id": { "type": "string", "description": "Order number, digits only" },
        "urgency":  { "enum": ["low", "normal", "high"] }
      }
    },
    "examples": [
      { "input": "I was charged twice for order #1200", "output": { "category": "billing", "order_id": "1200" } }
    ]
  }'
```

`201` the first time, `200` when the same definition is sent again. A version is
immutable: a changed definition gets `409 task_exists`, so register `1.1.0`.
Then interpret with the id as the domain:

```bash
curl -sS https://api.aquidify.com/v1/interpret \
  -H "Authorization: Bearer $AQUIDIFY_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "domain": "support.ticket@1.0.0", "input": "You charged my card twice for order #4411. Fix this ASAP!", "locale": "en" }'
```

```json
{
  "domain": "support.ticket@1.0.0",
  "parser_version": "1.0.0+…",
  "schema_version": "1.0.0",
  "interpretation": {
    "fields":   { "category": "billing", "order_id": "4411", "urgency": "high" },
    "evidence": [
      { "field": "category", "raw_text": "you charged my card twice" },
      { "field": "order_id", "raw_text": "order #4411" },
      { "field": "urgency",  "raw_text": "asap" }
    ],
    "uncertainties": [],
    "contradictions": []
  },
  "clarification": null,
  "meta": { "source": "ai", "cache": "miss", "latency_ms": 1580 }
}
```

Every field with a value has evidence quoting the input; a field the input does
not support comes back `null`. Schema rules:

- Top level `{"type": "object", "properties": {...}}`; property names lowercase snake_case.
- Keywords: `type`, `description`, `enum`, `properties`, `required`, `items`, `anyOf`. Up to 5 levels, 200 properties.
- A property not in `required` may be `null`. Mark a field required only when every input will state it.
- Put formatting rules in the field `description` ("digits only", "24h HH:MM").

`GET /v1/tasks` lists your task ids; `GET /v1/tasks/{id}` returns a task with the
exact `output_schema` its interpretations follow.

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
| 404 | `not_found` | no (no such task for this key) |
| 409 | `task_exists` | no, register a new version |
| 422 | `invalid_request`, `idempotency_mismatch`, `invalid_task`, `task_limit` | no |
| 422 | `interpretation_failed` | maybe, rephrased: the model gave no valid answer for this input |
| 422 | `provider_key_rejected` | no, your own AI key was refused; update or remove it |
| 429 | `rate_limited` | yes, after `Retry-After` seconds |
| 500 | `internal` | maybe, once; report it with the `request_id` if it repeats |
| 503 | `model_unavailable` | yes, after `Retry-After` seconds |
| 503 | `timeout` | yes, after `Retry-After` seconds |
