<?php

declare(strict_types=1);

namespace Aquidify\Search;

use LogicException;

/**
 * Turns an Aquidify answer into filters for a search engine: Meilisearch `filter`,
 * Algolia `filters` / `optionalFilters`, Elasticsearch / OpenSearch `bool` query.
 * Aquidify reads the query; the search engine finds and ranks the documents.
 *
 *     $f = Filters::from($response, ['roles' => 'category', 'locations' => 'city', 'schedules' => 'shift']);
 *     $index->search('', ['filter' => $f->meilisearch()]);
 *
 * $map is interpretation field => index attribute, or ['attribute' => ..., 'value' => fn (string $v): string]
 * to translate values into your index's vocabulary. Unmapped fields are ignored. A field whose values
 * are "name: value" pairs (a shop task's attributes: "colour: red", "size: 42") can route each name
 * to its own attribute with ['by_name' => ['colour' => 'pa_color', 'size' => 'pa_size']]; names
 * not listed are ignored rather than guessed.
 *
 * Shops: woocommerce() gives wc_get_products() arguments, shopify() Storefront API search
 * variables (see each method for the attribute names they understand).
 *
 * Per item (hiring intents and custom-task fields share the shape value/polarity/strength):
 *   include + required                -> filter; several values for one attribute are OR-ed
 *   include + acceptable/conditional  -> widens that filter (never filters on its own)
 *   include + preferred               -> boost only (Algolia optionalFilters, Elasticsearch should)
 *   exclude + required                -> NOT
 *   a {min, max} object (salary, price) -> numeric range; a plain string -> equality
 * "any" (explicit_any) and anything not said produce no filter at all.
 * Several searches in one sentence (hiring intents) are OR-ed groups; Algolia cannot OR groups,
 * so run searches() as a multi-query there.
 */
final class Filters
{
    /** @param list<array{must: array<string, list<string>>, widen: array<string, list<string>>, not: array<string, list<string>>, prefer: array<string, list<string>>, range: array<string, array{0: float|int|null, 1: float|int|null}>}> $groups */
    private function __construct(private readonly array $groups) {}

    /**
     * @param  array<string, mixed>  $response  the interpret() response
     * @param  array<string, string|array{attribute: string, value?: callable(string): string}>  $map
     */
    public static function from(array $response, array $map): self
    {
        $in = $response['interpretation'] ?? [];
        $units = isset($in['fields']) ? [$in['fields']] : ($in['intents'] ?? []);
        $groups = [];
        foreach ($units as $unit) {
            $g = ['must' => [], 'widen' => [], 'not' => [], 'prefer' => [], 'range' => []];
            foreach ($map as $field => $to) {
                $byName = is_array($to) ? ($to['by_name'] ?? null) : null;
                $attr = is_array($to) ? ($to['attribute'] ?? '') : $to;
                $tr = is_array($to) && isset($to['value']) ? $to['value'] : static fn (string $v): string => $v;
                $v = $unit[$field] ?? null;
                if ($v === null || $v === [] || $v === false) {
                    continue;
                }
                if (is_array($v) && (array_key_exists('min', $v) || array_key_exists('max', $v))) {
                    if (($v['min'] ?? null) !== null || ($v['max'] ?? null) !== null) {
                        $g['range'][$attr] = [$v['min'] ?? null, $v['max'] ?? null];
                    }

                    continue;
                }
                if (! is_array($v)) {
                    $g['must'][$attr][] = $tr((string) $v);

                    continue;
                }
                foreach ($v as $item) {
                    $value = is_array($item) ? ($item['value'] ?? null) : $item;
                    if ($value === null || $value === '') {
                        continue; // e.g. "near me": no place to filter on
                    }
                    $value = (string) $value;
                    $itemAttr = $attr;
                    if ($byName !== null) {
                        // "colour: red" -> the attribute mapped for "colour", value "red"
                        [$name, $rest] = array_pad(array_map('trim', explode(':', $value, 2)), 2, null);
                        $itemAttr = $byName[strtolower((string) $name)] ?? null;
                        if ($itemAttr === null || $rest === null || $rest === '') {
                            continue; // a name the shop did not map: ignored, not guessed
                        }
                        $value = $rest;
                    }
                    $value = $tr($value);
                    $polarity = is_array($item) ? ($item['polarity'] ?? 'include') : 'include';
                    $strength = is_array($item) ? ($item['strength'] ?? 'required') : 'required';
                    $bucket = match (true) {
                        $polarity === 'exclude' && $strength === 'required' => 'not',
                        $polarity === 'exclude' => null,
                        $strength === 'required' => 'must',
                        $strength === 'preferred' => 'prefer',
                        default => 'widen',
                    };
                    if ($bucket !== null) {
                        $g[$bucket][$itemAttr][] = $value;
                    }
                }
            }
            foreach ($g['widen'] as $attr => $values) {
                if (isset($g['must'][$attr])) {
                    $g['must'][$attr] = [...$g['must'][$attr], ...$values];
                }
            }
            foreach (['must', 'not', 'prefer'] as $k) {
                $g[$k] = array_map(static fn (array $vs) => array_values(array_unique($vs)), $g[$k]);
            }
            $groups[] = $g;
        }

        return new self($groups);
    }

    /** One Filters per search the person described (usually one). */
    public function searches(): array
    {
        return array_map(static fn (array $g) => new self([$g]), $this->groups);
    }

    /** Meilisearch `filter` expression ('' when nothing to filter). */
    public function meilisearch(): string
    {
        $q = static fn (string $s): string => '"'.addcslashes($s, '"\\').'"';
        $groups = array_filter(array_map(static function (array $g) use ($q): string {
            $parts = [];
            foreach ($g['must'] as $attr => $vs) {
                $parts[] = count($vs) === 1 ? "{$attr} = {$q($vs[0])}" : "{$attr} IN [".implode(', ', array_map($q, $vs)).']';
            }
            foreach ($g['not'] as $attr => $vs) {
                $parts[] = "{$attr} NOT IN [".implode(', ', array_map($q, $vs)).']';
            }
            foreach ($g['range'] as $attr => [$min, $max]) {
                $min !== null && $parts[] = "{$attr} >= {$min}";
                $max !== null && $parts[] = "{$attr} <= {$max}";
            }

            return implode(' AND ', $parts);
        }, $this->groups));

        return self::orGroups($groups);
    }

    /** Algolia `filters` for one search. */
    public function algolia(): string
    {
        $g = $this->single('algolia');
        $q = static fn (string $attr, string $v): string => $attr.':"'.addcslashes($v, '"\\').'"';
        $parts = [];
        foreach ($g['must'] as $attr => $vs) {
            $or = array_map(static fn ($v) => $q($attr, $v), $vs);
            $parts[] = count($or) === 1 ? $or[0] : '('.implode(' OR ', $or).')';
        }
        foreach ($g['not'] as $attr => $vs) {
            foreach ($vs as $v) {
                $parts[] = 'NOT '.$q($attr, $v);
            }
        }
        foreach ($g['range'] as $attr => [$min, $max]) {
            $min !== null && $parts[] = "{$attr} >= {$min}";
            $max !== null && $parts[] = "{$attr} <= {$max}";
        }

        return implode(' AND ', $parts);
    }

    /** Algolia `optionalFilters` (boosts) for one search. @return list<string> */
    public function algoliaOptional(): array
    {
        $out = [];
        foreach ($this->single('algoliaOptional')['prefer'] as $attr => $vs) {
            foreach ($vs as $v) {
                $out[] = $attr.':'.$v;
            }
        }

        return $out;
    }

    /**
     * Elasticsearch / OpenSearch query: filters and exclusions in filter context, preferences
     * as `should` (scoring only). Map text fields to their keyword sub-field (e.g. city.keyword).
     *
     * @return array<string, mixed>
     */
    public function elasticsearch(): array
    {
        $bools = array_map(static function (array $g): array {
            $bool = [];
            foreach ($g['must'] as $attr => $vs) {
                $bool['filter'][] = ['terms' => [$attr => $vs]];
            }
            foreach ($g['range'] as $attr => [$min, $max]) {
                $bool['filter'][] = ['range' => [$attr => array_filter(['gte' => $min, 'lte' => $max], static fn ($x) => $x !== null)]];
            }
            foreach ($g['not'] as $attr => $vs) {
                $bool['must_not'][] = ['terms' => [$attr => $vs]];
            }
            foreach ($g['prefer'] as $attr => $vs) {
                $bool['should'][] = ['terms' => [$attr => $vs]];
            }

            return ['bool' => $bool ?: ['must' => [['match_all' => (object) []]]]];
        }, $this->groups);

        return match (count($bools)) {
            0 => ['match_all' => (object) []],
            1 => $bools[0],
            default => ['bool' => ['should' => $bools, 'minimum_should_match' => 1]],
        };
    }

    /**
     * WooCommerce: arguments for wc_get_products() (or a product WP_Query) for one search.
     *
     * Attribute names: 's' is the search text; '_price' (or any attribute that gets a range)
     * is a numeric meta range; everything else is a product taxonomy, filtered by term slug:
     * 'pa_color', 'pa_size', 'product_cat', 'product_brand' (core since WooCommerce 9.6).
     * Excluded values become NOT IN, so "just not Nike" is honoured. Prices are compared as
     * numbers in the shop's currency: convert or drop a price in another currency first.
     *
     *     $f = Filters::from($response, [
     *         'products' => 's',
     *         'attributes' => ['by_name' => ['colour' => 'pa_color', 'size' => 'pa_size']],
     *         'brands' => 'product_brand',
     *         'price' => '_price',
     *     ]);
     *     wc_get_products($f->woocommerce());
     *
     * @return array<string, mixed>
     */
    public function woocommerce(): array
    {
        $g = $this->single('woocommerce');
        $slug = static fn (string $v): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-');
        $args = [];
        $tax = [];
        foreach ($g['must'] as $attr => $vs) {
            if ($attr === 's') {
                $args['s'] = implode(' ', $vs);

                continue;
            }
            $tax[] = ['taxonomy' => $attr, 'field' => 'slug', 'terms' => array_map($slug, $vs), 'operator' => 'IN'];
        }
        foreach ($g['not'] as $attr => $vs) {
            if ($attr !== 's') {
                $tax[] = ['taxonomy' => $attr, 'field' => 'slug', 'terms' => array_map($slug, $vs), 'operator' => 'NOT IN'];
            }
        }
        if ($tax) {
            $args['tax_query'] = ['relation' => 'AND', ...$tax];
        }
        $meta = [];
        foreach ($g['range'] as $attr => [$min, $max]) {
            $min !== null && $meta[] = ['key' => $attr, 'value' => $min, 'compare' => '>=', 'type' => 'NUMERIC'];
            $max !== null && $meta[] = ['key' => $attr, 'value' => $max, 'compare' => '<=', 'type' => 'NUMERIC'];
        }
        if ($meta) {
            $args['meta_query'] = ['relation' => 'AND', ...$meta];
        }

        return $args;
    }

    /**
     * Shopify: variables for the Storefront API `search` query for one search:
     * `search(query: $query, productFilters: $productFilters, types: [PRODUCT])`.
     *
     * Attribute names: 'query' is the search text; 'option:Color' (any 'option:<Name>') is a
     * variant option; 'vendor', 'productType' and 'tag' are the product fields of the same
     * name; 'price' takes a range. Shopify's product filters can only include, never exclude,
     * so excluded values are left out: shopifyLeftOut() lists them instead of guessing.
     *
     * @return array{query: string, productFilters: list<array<string, mixed>>}
     */
    public function shopify(): array
    {
        $g = $this->single('shopify');
        $filters = [];
        foreach ($g['must'] as $attr => $vs) {
            if ($attr === 'query') {
                continue;
            }
            foreach ($vs as $v) {
                $filters[] = match (true) {
                    str_starts_with($attr, 'option:') => ['variantOption' => ['name' => substr($attr, 7), 'value' => $v]],
                    $attr === 'vendor' => ['productVendor' => $v],
                    default => [$attr => $v],
                };
            }
        }
        foreach ($g['range'] as [$min, $max]) {
            $filters[] = ['price' => array_filter(['min' => $min, 'max' => $max], static fn ($x) => $x !== null)];
        }

        return ['query' => implode(' ', $g['must']['query'] ?? []), 'productFilters' => $filters];
    }

    /** What shopify() had to leave out (exclusions), as "attribute != value". @return list<string> */
    public function shopifyLeftOut(): array
    {
        $out = [];
        foreach ($this->single('shopify')['not'] as $attr => $vs) {
            foreach ($vs as $v) {
                $out[] = "{$attr} != {$v}";
            }
        }

        return $out;
    }

    /** @param list<string> $groups */
    private static function orGroups(array $groups): string
    {
        $groups = array_values(array_filter($groups, static fn ($g) => $g !== ''));

        return count($groups) > 1 ? '('.implode(') OR (', $groups).')' : ($groups[0] ?? '');
    }

    /** @return array{must: array<string, list<string>>, widen: array<string, list<string>>, not: array<string, list<string>>, prefer: array<string, list<string>>, range: array<string, array{0: float|int|null, 1: float|int|null}>} */
    private function single(string $method): array
    {
        if (count($this->groups) > 1) {
            throw new LogicException("The text describes several searches and {$method}() takes one. Call {$method}() on each of searches() (a multi-query).");
        }

        return $this->groups[0] ?? ['must' => [], 'widen' => [], 'not' => [], 'prefer' => [], 'range' => []];
    }
}
