<?php

declare(strict_types=1);

namespace Aquidify\Laravel\Facades;

use Aquidify\AquidifyException;
use Aquidify\Interpreter;
use Aquidify\Testing\FakeClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array interpret(string $domain, string $input, string $locale, array $options = [])
 * @method static void assertInterpreted(?callable $filter = null)
 * @method static void assertNothingInterpreted()
 *
 * @see Interpreter
 */
final class Aquidify extends Facade
{
    /**
     * Replace the client for this test: no network, no key.
     *
     *     Aquidify::fake(['skladišče' => FakeClient::interpretation([...])]);
     *     Aquidify::fake(['*' => FakeClient::error('model_unavailable')]);
     *
     * @param  array<string, array<string, mixed>|AquidifyException>  $responses
     */
    public static function fake(array $responses = []): FakeClient
    {
        $fake = new FakeClient($responses);
        static::swap($fake); // also rebinds Interpreter in the container, so injected code gets the fake

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Interpreter::class;
    }
}
