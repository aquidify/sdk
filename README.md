# Aquidify SDKs

Official clients for the [Aquidify](https://aquidify.com) API: turn what people
type into validated, structured intent your software can act on.

```text
"Iščem delo v skladišču v Ljubljani, brez nočnih."
   → roles: warehouse · locations: Ljubljana · schedules: night (exclude)
```

Every extracted item quotes the exact span it came from (`raw_text`),
uncertainty is kept instead of guessed away, and "unknown" is never confused
with "any". **Aquidify interprets; your application decides.**

| Language | Folder | Install |
|---|---|---|
| PHP 8.2+ | [`php/`](php) | `composer require aquidify/sdk` |
| TypeScript / JavaScript | [`js/`](js) | `npm install @aquidify/sdk` |
| Go | [`go/`](go) | `go get github.com/aquidify/sdk/go` |
| Python 3.9+ | [`python/`](python) | `pip install aquidify` |
| Java 17+ | [`java/`](java) | Maven `com.aquidify:aquidify-sdk` |
| Rust | [`rust/`](rust) | `cargo add aquidify` |
| curl / any language | [`curl/`](curl) | — |

Not listed? Generate a client from [`openapi.yaml`](openapi.yaml), the source
of truth for every SDK here. Output shapes per domain are JSON Schemas in
[`schemas/`](schemas).

All clients read `AQUIDIFY_API_KEY` from the environment when no key is passed.
**Keep keys server-side**: never ship one to a browser or mobile app.

## PHP

```php
$aq = new Aquidify\Client();   // AQUIDIFY_API_KEY
$r  = $aq->interpret('hiring.candidate', 'Iščem delo v skladišču v Ljubljani, brez nočnih.', 'sl-SI');

$r['interpretation']['intents'][0]['roles'][0]['value'];   // "warehouse"
$r['clarification'];                                       // null
```

## TypeScript / JavaScript

```ts
import { Aquidify, type HiringCandidateInterpretation } from "@aquidify/sdk";

const aq = new Aquidify();
const r = await aq.interpret<HiringCandidateInterpretation>({
  domain: "hiring.candidate",
  input: "Iščem delo v skladišču v Ljubljani, brez nočnih.",
  locale: "sl-SI",
});
r.interpretation.intents[0].schedules[0]; // { value: "night", polarity: "exclude", … }
```

## Go

```go
c, _ := aquidify.New("")
r, err := c.Interpret(ctx, aquidify.Request{Domain: "hiring.candidate", Input: "…", Locale: "sl-SI"})
// r.Interpretation is json.RawMessage: unmarshal into your own types
```

## Python

```python
from aquidify import Aquidify
r = Aquidify().interpret("hiring.candidate", "Iščem delo v skladišču v Ljubljani, brez nočnih.", "sl-SI")
r["interpretation"]["intents"][0]["locations"][0]["value"]  # "Ljubljana"
```

## Java

```java
var r = new Aquidify(null).interpret("hiring.candidate", "Iščem delo v skladišču v Ljubljani, brez nočnih.", "sl-SI");
r.interpretation().at("/intents/0/roles/0/value").asText(); // "warehouse"
```

## Rust

```rust
let r = aquidify::Client::from_env()?
    .interpret(&aquidify::Request::new("hiring.candidate", "Iščem delo …", "sl-SI"))?;
r.interpretation["intents"][0]["roles"][0]["value"]; // "warehouse"
```

## Errors

Every client raises one error type carrying the HTTP `status`, the API's
stable `code` (`unauthorized`, `rate_limited`, `invalid_request`,
`model_unavailable`, …, or `network`), the `request_id` and `retry_after`, plus
a `retryable` check. The full table is in [`curl/`](curl#errors). Clients do not
retry on their own: retry `retryable` errors after `retry_after`, ideally with
an `Idempotency-Key`.

## Versions

Responses carry `parser_version` and `schema_version`. Pin both in production
to keep the shape stable while newer parsers roll out. A released schema version
never changes; changes ship as new versions.

## Testing the SDKs

Each SDK has a live smoke test that makes no model calls (it costs nothing):

```bash
export AQUIDIFY_API_KEY=...
composer install && composer test                  # PHP
cd js && npm install && npm test                   # TypeScript
cd go && go test ./...                             # Go
cd python && uv run python tests/smoke.py          # Python
cd java && mvn -q test                             # Java
cd rust && cargo test                              # Rust
```

## License

MIT
