# @aquidify/sdk

TypeScript/JavaScript client for the [Aquidify](https://aquidify.com) API:
turn what people type into validated, structured intent.

Aquidify interprets; your application decides. You send natural language and a
task schema, you get back structured intent that matches that schema — with
uncertainty kept instead of guessed away, and `unknown` never confused with
`any`.

```bash
npm install @aquidify/sdk
```

Node 18+, ESM, types included.

## Interpret

```ts
import { Aquidify } from '@aquidify/sdk';

const aq = new Aquidify();   // reads AQUIDIFY_API_KEY, or pass { apiKey }

const r = await aq.interpret({
  domain: 'hiring.candidate',
  input: 'Looking for warehouse work in Ljubljana, no night shifts.',
  locale: 'en-GB',
});

r.interpretation.intents[0].roles[0].value;   // "warehouse"
r.clarification;                              // null when nothing is ambiguous
```

The constructor throws if no key is found. **Keep the key server-side** — never
ship one to a browser or a mobile app.

`clarification` is not an error. It is Aquidify saying it will not guess: ask
the person the question it hands you, then interpret again.

## Your own task, any domain

```ts
await aq.putTask('support.ticket@1.0.0', {
  instructions: 'Classify customer support emails for routing.',
  schema: {
    type: 'object',
    properties: {
      category: { enum: ['billing', 'bug', 'refund', 'other'] },
      order_id: { type: 'string', description: 'Order number, digits only' },
    },
  },
});
```

The same definition again is a no-op; a changed one throws `task_exists` —
register a new version instead of moving an old one. `getTask(id)` and
`listTasks()` read back what this key has registered.

## Search filters

`SearchFilters` turns an interpretation into filters for the search engine you
already run, so interpretation never becomes a second source of truth. You map
Aquidify's fields onto your own attributes:

```ts
import { SearchFilters } from '@aquidify/sdk';

const filters = SearchFilters.from(r, {
  location: 'city',
  role: { attribute: 'category', value: (v) => v.toLowerCase() },
});

filters.meilisearch();        // filter expression
filters.algolia();            // filters
filters.algoliaOptional();    // optionalFilters (boosts)
filters.elasticsearch();      // bool query
```

`searches()` splits it when someone described more than one search at once.

## Options

| Option | Default |
|---|---|
| `apiKey` | `AQUIDIFY_API_KEY` from the environment |
| `baseUrl` | `https://api.aquidify.com` |
| `timeoutMs` | `60000` |
| `fetch` | the global `fetch` |

Failures throw `AquidifyError` with `status`, `code`, `requestId` and, when the
server sent one, `retryAfter`. `error.retryable` says whether the same request
may succeed later. Treat a failure as "no interpretation available" and fall
back to whatever your app did before — never block the person on it.

## More

- Docs: <https://docs.aquidify.com>
- Other languages, and the `openapi.yaml` every client is generated from:
  <https://github.com/aquidify/sdk>

MIT licensed.
