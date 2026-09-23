<p align="center"><a href="https://aquidify.com"><img src=".github/logo.svg" alt="Aquidify" width="96"></a></p>

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

| Language | Folder | Install | Registry |
|---|---|---|---|
| PHP 8.2+ / Laravel | [`php/`](php) | `composer require aquidify/sdk` | ✓ published |
| TypeScript / JavaScript | [`js/`](js) | build from source: `cd js && npm install && npm run build` | npm `@aquidify/sdk`, coming soon |
| Go | [`go/`](go) | `go get github.com/aquidify/sdk/go@v0.1.0` | ✓ published |
| Python 3.9+ | [`python/`](python) | `pip install "git+https://github.com/aquidify/sdk@v0.1.0#subdirectory=python"` | PyPI `aquidify`, coming soon |
| Java 17+ | [`java/`](java) | `cd java && mvn install`, then depend on `com.aquidify:aquidify-sdk:0.1.0` | Maven Central, coming soon |
| Rust | [`rust/`](rust) | `aquidify = { git = "https://github.com/aquidify/sdk", tag = "v0.1.0" }` | crates.io `aquidify`, coming soon |
| curl / any language | [`curl/`](curl) | nothing to install | — |

Not listed? Generate a client from [`openapi.yaml`](openapi.yaml), the source
of truth for every SDK here. Output shapes per domain are JSON Schemas in
[`schemas/`](schemas).

All clients read `AQUIDIFY_API_KEY` from the environment when no key is passed.
**Keep keys server-side**: never ship one to a browser or mobile app.

## PHP

```bash
composer require aquidify/sdk
```

Laravel discovers the service provider and facade on its own; publish the
config with `php artisan vendor:publish --tag=aquidify-config` when you want to
change the base URL or timeout.

```php
$aq = new Aquidify\Client();   // AQUIDIFY_API_KEY
$r  = $aq->interpret('hiring.candidate', 'Iščem delo v skladišču v Ljubljani, brez nočnih.', 'sl-SI');

$r['interpretation']['intents'][0]['roles'][0]['value'];   // "warehouse"
$r['clarification'];                                       // null
```

Your own task, for any industry (see [curl/README.md](curl/README.md#your-own-task-any-industry)):

```php
$aq->putTask('support.ticket@1.0.0', [
    'instructions' => 'Classify customer support emails for routing.',
    'schema' => ['type' => 'object', 'properties' => [
        'category' => ['enum' => ['billing', 'bug', 'refund', 'other']],
        'order_id' => ['type' => 'string', 'description' => 'Order number, digits only'],
    ]],
]);
$fields = $aq->interpret('support.ticket@1.0.0', $emailBody, 'en')['interpretation']['fields'];
```

Every SDK can interpret with a task id as the domain. Registering tasks
(`putTask` / `getTask` / `listTasks`) is in the PHP, TypeScript, Go and Python
SDKs (`put_task` … in Python); from Java and Rust use plain HTTP for now.

## Laravel

The PHP package includes a Laravel adapter (auto-discovered): config, a
service provider, the `Aquidify` facade and a fake for tests.

```bash
# .env
AQUIDIFY_API_KEY=...
AQUIDIFY_TIMEOUT=8            # seconds; keep short in web requests and fall back
AQUIDIFY_PARSER_VERSION=1.0.0 # pinned by default
AQUIDIFY_SCHEMA_VERSION=1.0.0

php artisan vendor:publish --tag=aquidify-config   # optional
```

```php
use Aquidify\Interpreter;
use Aquidify\Laravel\Facades\Aquidify;

// inject…
public function __construct(private Interpreter $aquidify) {}
$r = $this->aquidify->interpret('hiring.candidate', $text, app()->getLocale());

// …or use the facade
$r = Aquidify::interpret('hiring.candidate', $text, 'sl');
```

Tests never touch the network:

```php
use Aquidify\Testing\FakeClient;

Aquidify::fake([
    'skladišče' => FakeClient::interpretation(['intents' => [/* … */]]),  // input substring => response
    '*'         => FakeClient::error('model_unavailable'),                // everything else fails
]);

// … exercise your code …

Aquidify::assertInterpreted(fn ($call) => $call['locale'] === 'sl');
```

`Aquidify::fake()` replaces both the facade and every injected `Interpreter`.
Without Laravel, use `new FakeClient([...])` directly.

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

## Search engines: Meilisearch, Algolia, Elasticsearch

Aquidify reads the query; your search engine finds and ranks the documents. `Filters` (PHP) and
`SearchFilters` (TypeScript) turn an answer into that engine's filter. You map interpretation fields
to your index attributes; unmapped fields are ignored.

```php
use Aquidify\Search\Filters;

$r = $aq->interpret('hiring.candidate', 'Warehouse job in Maribor, mornings only, no weekends', 'en');
$f = Filters::from($r, ['roles' => 'category', 'locations' => 'city', 'schedules' => 'shift', 'salary' => 'salary']);

$meili->index('jobs')->search('', ['filter' => $f->meilisearch()]);
// category = "warehouse" AND city = "Maribor" AND shift = "morning" AND shift NOT IN ["weekend"]

$algolia->searchSingleIndex('jobs', ['filters' => $f->algolia(), 'optionalFilters' => $f->algoliaOptional()]);
$es->search(['index' => 'jobs', 'body' => ['query' => $f->elasticsearch()]]);   // OpenSearch: same DSL
```

```ts
import { SearchFilters } from "@aquidify/sdk";

const f = SearchFilters.from(r, { roles: "category", locations: { attribute: "city", value: (v) => v.toLowerCase() } });
await index.search("", { filter: f.meilisearch() });
```

| In the answer | Becomes |
| --- | --- |
| `include` + `required` | a filter; several values for one attribute are OR-ed |
| `acceptable` / `conditional` | widens that filter, never filters on its own |
| `preferred` | a boost only: Algolia `optionalFilters`, Elasticsearch `should` (Meilisearch: none) |
| `exclude` + `required` | `NOT` |
| `{min, max}` (salary, price) | a numeric range |
| "any", or not said | nothing: no filter |

One sentence can describe several searches (hiring `intents`). Meilisearch and Elasticsearch get them
OR-ed; Algolia cannot OR groups, so run `searches()` as a multi-query. For Elasticsearch, map text
fields to their keyword sub-field (`city.keyword`). Translate values into your index's vocabulary with
`['attribute' => 'city', 'value' => fn ($v) => ...]`.

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
composer install && composer test                  # PHP + Laravel adapter
cd js && npm install && npm test                   # TypeScript
cd go && go test ./...                             # Go
cd python && uv run python tests/smoke.py          # Python
cd java && mvn -q test                             # Java
cd rust && cargo test                              # Rust
```

## License

MIT
