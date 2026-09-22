// Offline tests for SearchFilters (no key, no network): same cases as php/tests/Search/FiltersTest.php.
import assert from "node:assert/strict";
import { SearchFilters } from "./dist/index.js";

const MAP = { roles: "category", locations: "city", schedules: "shift", salary: "salary" };
const item = (value, polarity = "include", strength = "required") => ({ value, polarity, strength, condition: null, raw_text: value });
const intents = (...xs) => ({ interpretation: { intents: xs } });

// "Iščem delo v skladišču v Ljubljani, brez nočnih." (shape of the real answer)
let f = SearchFilters.from(intents({ roles: [item("warehouse")], locations: [{ ...item("Ljubljana"), radius_km: null }], schedules: [item("night", "exclude")], work_modes: [], salary: null }), MAP);
assert.equal(f.meilisearch(), 'category = "warehouse" AND city = "Ljubljana" AND shift NOT IN ["night"]');
assert.equal(f.algolia(), 'category:"warehouse" AND city:"Ljubljana" AND NOT shift:"night"');
assert.deepEqual(f.elasticsearch(), { bool: { filter: [{ terms: { category: ["warehouse"] } }, { terms: { city: ["Ljubljana"] } }], must_not: [{ terms: { shift: ["night"] } }] } });

// salary floor -> range
f = SearchFilters.from(intents({ roles: [item("cook")], salary: { min: 1800, max: null, currency: "EUR", basis: "net" } }), MAP);
assert.equal(f.meilisearch(), 'category = "cook" AND salary >= 1800');

// preferences boost, conditions only widen
const pref = intents({ schedules: [item("morning", "include", "preferred"), item("afternoon", "include", "conditional")] });
f = SearchFilters.from(pref, MAP);
assert.equal(f.meilisearch(), "", "a preference must never hide results");
assert.deepEqual(f.algoliaOptional(), ["shift:morning"]);
pref.interpretation.intents[0].schedules.push(item("evening"));
assert.equal(SearchFilters.from(pref, MAP).meilisearch(), 'shift IN ["evening", "afternoon"]');

// several searches: OR groups; Algolia needs a multi-query
f = SearchFilters.from(intents({ roles: [item("warehouse")], locations: [item("Celje")] }, { roles: [item("driver")] }), MAP);
assert.equal(f.meilisearch(), '(category = "warehouse" AND city = "Celje") OR (category = "driver")');
assert.equal(f.searches()[1].algolia(), 'category:"driver"');
assert.throws(() => f.algolia(), /multi-query/);

// custom task fields, value translation, quoting
f = SearchFilters.from(
  { interpretation: { fields: { request_type: "buy", products: [item("running shoes")], brands: [item("Nike", "exclude")], attributes: [item('say "hi"')], price: { min: null, max: 80, currency: "EUR" }, recipient: null } } },
  { products: { attribute: "type", value: (v) => v.replaceAll(" ", "-") }, brands: "brand", attributes: "tags", price: "price" },
);
assert.equal(f.meilisearch(), 'type = "running-shoes" AND tags = "say \\"hi\\"" AND brand NOT IN ["Nike"] AND price <= 80');

// nothing said -> no filter
f = SearchFilters.from(intents({ locations: [{ value: null, radius_km: 5, polarity: "include", strength: "required" }], roles: [] }), MAP);
assert.equal(f.meilisearch(), "");
assert.deepEqual(f.elasticsearch(), { bool: { must: [{ match_all: {} }] } });

console.log("filters: ok");
