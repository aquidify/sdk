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
 * to translate values into your index's vocabulary. Unmapped fields are ignored.
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
                $attr = is_array($to) ? $to['attribute'] : $to;
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
                    $value = $tr((string) $value);
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
                        $g[$bucket][$attr][] = $value;
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
            throw new LogicException("The text describes several searches; Algolia cannot OR them in one filter. Call {$method}() on each of searches() (a multi-query).");
        }

        return $this->groups[0] ?? ['must' => [], 'widen' => [], 'not' => [], 'prefer' => [], 'range' => []];
    }
}
