<?php

declare(strict_types=1);

namespace Aquidify\Tests\Search;

use Aquidify\Search\Filters;
use PHPUnit\Framework\TestCase;

/** Shops: WooCommerce and Shopify outputs, on a real answer of a shop task. */
final class CommerceFiltersTest extends TestCase
{
    private static function item(string $value, string $polarity = 'include'): array
    {
        return ['value' => $value, 'polarity' => $polarity, 'strength' => 'required', 'condition' => null];
    }

    /** The real answer (gpt-5.4-mini, 2026-09-22) to "Red running shoes, size 42, up to €80, just not Nike." */
    private static function runningShoes(): array
    {
        return ['interpretation' => ['fields' => [
            'products' => [self::item('running shoes')],
            'attributes' => [self::item('colour: red'), self::item('size: 42')],
            'brands' => [self::item('nike', 'exclude')],
            'price' => ['currency' => 'EUR', 'min' => null, 'max' => 80, 'period' => 'once'],
            'request_type' => 'buy',
        ]]];
    }

    public function test_woocommerce_gets_search_attributes_brand_exclusion_and_price(): void
    {
        $f = Filters::from(self::runningShoes(), [
            'products' => 's',
            'attributes' => ['by_name' => ['colour' => 'pa_color', 'size' => 'pa_size']],
            'brands' => 'product_brand',
            'price' => '_price',
        ]);

        $this->assertSame([
            's' => 'running shoes',
            'tax_query' => [
                'relation' => 'AND',
                ['taxonomy' => 'pa_color', 'field' => 'slug', 'terms' => ['red'], 'operator' => 'IN'],
                ['taxonomy' => 'pa_size', 'field' => 'slug', 'terms' => ['42'], 'operator' => 'IN'],
                ['taxonomy' => 'product_brand', 'field' => 'slug', 'terms' => ['nike'], 'operator' => 'NOT IN'],
            ],
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_price', 'value' => 80, 'compare' => '<=', 'type' => 'NUMERIC'],
            ],
        ], $f->woocommerce());
    }

    public function test_shopify_gets_search_variables_and_names_what_it_cannot_express(): void
    {
        $f = Filters::from(self::runningShoes(), [
            'products' => 'query',
            'attributes' => ['by_name' => ['colour' => 'option:Color', 'color' => 'option:Color', 'size' => 'option:Size']],
            'brands' => 'vendor',
            'price' => 'price',
        ]);

        $this->assertSame([
            'query' => 'running shoes',
            'productFilters' => [
                ['variantOption' => ['name' => 'Color', 'value' => 'red']],
                ['variantOption' => ['name' => 'Size', 'value' => '42']],
                ['price' => ['max' => 80]],
            ],
        ], $f->shopify());
        // Shopify filters cannot exclude a vendor: said out loud, not silently dropped
        $this->assertSame(['vendor != nike'], $f->shopifyLeftOut());
    }

    public function test_attribute_names_the_shop_did_not_map_are_ignored_not_guessed(): void
    {
        $f = Filters::from(self::runningShoes(), [
            'attributes' => ['by_name' => ['size' => 'pa_size']], // no mapping for colour
        ]);

        $this->assertSame([
            'tax_query' => ['relation' => 'AND', ['taxonomy' => 'pa_size', 'field' => 'slug', 'terms' => ['42'], 'operator' => 'IN']],
        ], $f->woocommerce());
    }

    public function test_term_slugs_follow_wordpress_style(): void
    {
        $f = Filters::from(['interpretation' => ['fields' => [
            'brands' => [self::item('New Balance', 'exclude')],
        ]]], ['brands' => 'product_brand']);

        $this->assertSame(['new-balance'], $f->woocommerce()['tax_query'][0]['terms']);
    }
}
