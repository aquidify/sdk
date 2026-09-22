<?php

declare(strict_types=1);

namespace Aquidify\Tests\Search;

use Aquidify\Search\Filters;
use LogicException;
use PHPUnit\Framework\TestCase;

final class FiltersTest extends TestCase
{
    private const MAP = ['roles' => 'category', 'locations' => 'city', 'schedules' => 'shift', 'salary' => 'salary'];

    private static function item(string $value, string $polarity = 'include', string $strength = 'required'): array
    {
        return ['value' => $value, 'polarity' => $polarity, 'strength' => $strength, 'condition' => null, 'raw_text' => $value];
    }

    /** Shape of the real answer to "Iščem delo v skladišču v Ljubljani, brez nočnih." */
    private static function warehouse(): array
    {
        return ['interpretation' => ['intents' => [[
            'roles' => [self::item('warehouse')],
            'locations' => [self::item('Ljubljana') + ['radius_km' => null]],
            'schedules' => [self::item('night', 'exclude')],
            'work_modes' => [],
            'salary' => null,
        ]]]];
    }

    public function test_required_and_excluded_values_become_filters_in_every_engine(): void
    {
        $f = Filters::from(self::warehouse(), self::MAP);

        $this->assertSame('category = "warehouse" AND city = "Ljubljana" AND shift NOT IN ["night"]', $f->meilisearch());
        $this->assertSame('category:"warehouse" AND city:"Ljubljana" AND NOT shift:"night"', $f->algolia());
        $this->assertSame(['bool' => [
            'filter' => [['terms' => ['category' => ['warehouse']]], ['terms' => ['city' => ['Ljubljana']]]],
            'must_not' => [['terms' => ['shift' => ['night']]]],
        ]], $f->elasticsearch());
    }

    public function test_salary_floor_is_a_numeric_range(): void
    {
        // "Cook in Maribor, full time, from 1800 € net."
        $r = ['interpretation' => ['intents' => [[
            'roles' => [self::item('cook')],
            'salary' => ['min' => 1800, 'max' => null, 'currency' => 'EUR', 'basis' => 'net', 'period' => null, 'strength' => 'required', 'raw_text' => 'from 1800 € net'],
        ]]]];
        $f = Filters::from($r, self::MAP);

        $this->assertSame('category = "cook" AND salary >= 1800', $f->meilisearch());
        $this->assertSame(['range' => ['salary' => ['gte' => 1800]]], $f->elasticsearch()['bool']['filter'][1]);
    }

    public function test_preferences_boost_and_conditions_only_widen(): void
    {
        // "I prefer mornings, but afternoons are okay if the pay is higher."
        $r = ['interpretation' => ['intents' => [[
            'schedules' => [self::item('morning', 'include', 'preferred'), self::item('afternoon', 'include', 'conditional')],
        ]]]];
        $f = Filters::from($r, self::MAP);

        $this->assertSame('', $f->meilisearch(), 'a preference must never hide results');
        $this->assertSame(['shift:morning'], $f->algoliaOptional());
        $this->assertSame([['terms' => ['shift' => ['morning']]]], $f->elasticsearch()['bool']['should']);

        // with a required value, an acceptable/conditional one widens it
        $r['interpretation']['intents'][0]['schedules'][] = self::item('evening');
        $this->assertSame('shift IN ["evening", "afternoon"]', Filters::from($r, self::MAP)->meilisearch());
    }

    public function test_several_searches_are_or_groups_and_algolia_asks_for_a_multi_query(): void
    {
        $r = ['interpretation' => ['intents' => [
            ['roles' => [self::item('warehouse')], 'locations' => [self::item('Celje')]],
            ['roles' => [self::item('driver')]],
        ]]];
        $f = Filters::from($r, self::MAP);

        $this->assertSame('(category = "warehouse" AND city = "Celje") OR (category = "driver")', $f->meilisearch());
        $this->assertSame(1, $f->elasticsearch()['bool']['minimum_should_match']);
        $this->assertCount(2, $f->searches());
        $this->assertSame('category:"driver"', $f->searches()[1]->algolia());
        $this->expectException(LogicException::class);
        $f->algolia();
    }

    public function test_custom_task_fields_values_are_translated_and_quoted(): void
    {
        // shop.request: products want-list, a price object, a plain enum
        $r = ['interpretation' => ['fields' => [
            'request_type' => 'buy',
            'products' => [self::item('running shoes')],
            'brands' => [self::item('Nike', 'exclude')],
            'attributes' => [self::item('say "hi"')],
            'price' => ['min' => null, 'max' => 80, 'currency' => 'EUR', 'period' => null],
            'recipient' => null,
        ]]];
        $f = Filters::from($r, [
            'products' => ['attribute' => 'type', 'value' => fn (string $v) => str_replace(' ', '-', $v)],
            'brands' => 'brand',
            'attributes' => 'tags',
            'price' => 'price',
        ]);

        $this->assertSame('type = "running-shoes" AND tags = "say \"hi\"" AND brand NOT IN ["Nike"] AND price <= 80', $f->meilisearch());
        $this->assertSame('type:"running-shoes" AND tags:"say \"hi\"" AND NOT brand:"Nike" AND price <= 80', $f->algolia());
    }

    public function test_nothing_said_means_no_filter(): void
    {
        $r = ['interpretation' => ['intents' => [['locations' => [['value' => null, 'radius_km' => 5, 'polarity' => 'include', 'strength' => 'required']], 'roles' => []]]]];
        $f = Filters::from($r, self::MAP);

        $this->assertSame('', $f->meilisearch());
        $this->assertSame('', $f->algolia());
        $this->assertEquals(['bool' => ['must' => [['match_all' => (object) []]]]], $f->elasticsearch());
    }
}
