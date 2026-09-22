// Turns an Aquidify answer into search-engine filters: Meilisearch `filter`, Algolia
// `filters` / `optionalFilters`, Elasticsearch / OpenSearch `bool` query.
// Same rules as the PHP Aquidify\Search\Filters (see its docblock):
//   include+required -> filter (values OR-ed per attribute); acceptable/conditional only widen it;
//   preferred -> boost only; exclude+required -> NOT; {min,max} -> range; "any"/unsaid -> nothing.
//   Several searches in one sentence are OR-ed groups; Algolia needs searches() as a multi-query.

export type FieldMap = Record<string, string | { attribute: string; value?: (v: string) => string }>;

interface Group {
  must: Record<string, string[]>;
  widen: Record<string, string[]>;
  not: Record<string, string[]>;
  prefer: Record<string, string[]>;
  range: Record<string, [number | null, number | null]>;
}

const empty = (): Group => ({ must: {}, widen: {}, not: {}, prefer: {}, range: {} });
const push = (o: Record<string, string[]>, k: string, v: string) => (o[k] ??= []).push(v);
const uniq = (o: Record<string, string[]>) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, [...new Set(v)]]));
const quote = (s: string) => `"${s.replace(/["\\]/g, "\\$&")}"`;

export class SearchFilters {
  private constructor(private readonly groups: Group[]) {}

  static from(response: { interpretation: unknown }, map: FieldMap): SearchFilters {
    const inter = (response.interpretation ?? {}) as { fields?: Record<string, unknown>; intents?: Record<string, unknown>[] };
    const units = inter.fields ? [inter.fields] : (inter.intents ?? []);
    return new SearchFilters(
      units.map((unit) => {
        const g = empty();
        for (const [field, to] of Object.entries(map)) {
          const attr = typeof to === "string" ? to : to.attribute;
          const tr = typeof to === "string" || !to.value ? (v: string) => v : to.value;
          const v = unit[field];
          if (v == null || v === false || (Array.isArray(v) && !v.length)) continue;
          if (typeof v === "object" && !Array.isArray(v) && ("min" in v || "max" in v)) {
            const { min = null, max = null } = v as { min?: number | null; max?: number | null };
            if (min != null || max != null) g.range[attr] = [min, max];
            continue;
          }
          if (!Array.isArray(v)) {
            push(g.must, attr, tr(String(v)));
            continue;
          }
          for (const item of v) {
            const o = (typeof item === "object" && item) || {};
            const raw = typeof item === "object" && item ? (item as { value?: unknown }).value : item;
            if (raw == null || raw === "") continue; // e.g. "near me": no place to filter on
            const { polarity = "include", strength = "required" } = o as { polarity?: string; strength?: string };
            const bucket =
              polarity === "exclude" ? (strength === "required" ? "not" : null)
              : strength === "required" ? "must"
              : strength === "preferred" ? "prefer"
              : "widen";
            if (bucket) push(g[bucket], attr, tr(String(raw)));
          }
        }
        for (const [attr, vs] of Object.entries(g.widen)) if (g.must[attr]) g.must[attr].push(...vs);
        return { ...g, must: uniq(g.must), not: uniq(g.not), prefer: uniq(g.prefer) };
      }),
    );
  }

  /** One SearchFilters per search the person described (usually one). */
  searches(): SearchFilters[] {
    return this.groups.map((g) => new SearchFilters([g]));
  }

  /** Meilisearch `filter` expression ("" when nothing to filter). */
  meilisearch(): string {
    const groups = this.groups
      .map((g) => {
        const parts: string[] = [];
        for (const [a, vs] of Object.entries(g.must)) parts.push(vs.length === 1 ? `${a} = ${quote(vs[0])}` : `${a} IN [${vs.map(quote).join(", ")}]`);
        for (const [a, vs] of Object.entries(g.not)) parts.push(`${a} NOT IN [${vs.map(quote).join(", ")}]`);
        for (const [a, [min, max]] of Object.entries(g.range)) {
          if (min != null) parts.push(`${a} >= ${min}`);
          if (max != null) parts.push(`${a} <= ${max}`);
        }
        return parts.join(" AND ");
      })
      .filter(Boolean);
    return groups.length > 1 ? `(${groups.join(") OR (")})` : (groups[0] ?? "");
  }

  /** Algolia `filters` for one search. */
  algolia(): string {
    const g = this.single("algolia");
    const f = (a: string, v: string) => `${a}:${quote(v)}`;
    const parts: string[] = [];
    for (const [a, vs] of Object.entries(g.must)) parts.push(vs.length === 1 ? f(a, vs[0]) : `(${vs.map((v) => f(a, v)).join(" OR ")})`);
    for (const [a, vs] of Object.entries(g.not)) for (const v of vs) parts.push(`NOT ${f(a, v)}`);
    for (const [a, [min, max]] of Object.entries(g.range)) {
      if (min != null) parts.push(`${a} >= ${min}`);
      if (max != null) parts.push(`${a} <= ${max}`);
    }
    return parts.join(" AND ");
  }

  /** Algolia `optionalFilters` (boosts) for one search. */
  algoliaOptional(): string[] {
    return Object.entries(this.single("algoliaOptional").prefer).flatMap(([a, vs]) => vs.map((v) => `${a}:${v}`));
  }

  /** Elasticsearch / OpenSearch query. Map text fields to their keyword sub-field (city.keyword). */
  elasticsearch(): Record<string, unknown> {
    const bools = this.groups.map((g) => {
      const bool: Record<string, unknown[]> = {};
      const add = (k: string, c: unknown) => (bool[k] ??= []).push(c);
      for (const [a, vs] of Object.entries(g.must)) add("filter", { terms: { [a]: vs } });
      for (const [a, [min, max]] of Object.entries(g.range))
        add("filter", { range: { [a]: { ...(min != null && { gte: min }), ...(max != null && { lte: max }) } } });
      for (const [a, vs] of Object.entries(g.not)) add("must_not", { terms: { [a]: vs } });
      for (const [a, vs] of Object.entries(g.prefer)) add("should", { terms: { [a]: vs } });
      return { bool: Object.keys(bool).length ? bool : { must: [{ match_all: {} }] } };
    });
    if (!bools.length) return { match_all: {} };
    return bools.length === 1 ? bools[0] : { bool: { should: bools, minimum_should_match: 1 } };
  }

  private single(method: string): Group {
    if (this.groups.length > 1)
      throw new Error(`The text describes several searches; Algolia cannot OR them in one filter. Call ${method}() on each of searches() (a multi-query).`);
    return this.groups[0] ?? empty();
  }
}
