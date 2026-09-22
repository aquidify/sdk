<?php

declare(strict_types=1);

namespace Aquidify\Testing;

use Aquidify\AquidifyException;
use Aquidify\Interpreter;
use PHPUnit\Framework\Assert;

/**
 * In-memory stand-in for the API. No network, no key.
 *
 *     $fake = new FakeClient([
 *         'skladišče' => FakeClient::interpretation(['intents' => [...]]),
 *     ]);
 *
 * Responses are matched by substring of the input (first match wins); anything
 * else gets an empty interpretation. A queued AquidifyException is thrown.
 * Works without Laravel; in Laravel use Aquidify::fake(...).
 */
final class FakeClient implements Interpreter
{
    /** @var list<array{domain: string, input: string, locale: string, options: array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param  array<string, array<string, mixed>|AquidifyException>  $responses  input substring => response or exception
     */
    public function __construct(private array $responses = []) {}

    public function interpret(string $domain, string $input, string $locale, array $options = []): array
    {
        $this->calls[] = compact('domain', 'input', 'locale', 'options');

        foreach ($this->responses as $needle => $response) {
            if ($needle === '*' || str_contains(mb_strtolower($input), mb_strtolower((string) $needle))) {
                if ($response instanceof AquidifyException) {
                    throw $response;
                }

                return $response + ['domain' => $domain];
            }
        }

        return self::interpretation(['intents' => []]) + ['domain' => $domain];
    }

    /**
     * A well-formed response around an interpretation.
     *
     * @param  array<string, mixed>  $interpretation
     * @param  array<string, mixed>|null  $clarification
     * @return array<string, mixed>
     */
    public static function interpretation(array $interpretation, ?array $clarification = null): array
    {
        return [
            'request_id' => 'fake',
            'parser_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'interpretation' => $interpretation + ['intents' => [], 'uncertainties' => [], 'contradictions' => []],
            'clarification' => $clarification,
            'meta' => ['source' => 'ai', 'cache' => 'miss', 'latency_ms' => 0],
        ];
    }

    /** An error as the API would return it. */
    public static function error(string $code = 'model_unavailable', int $status = 503): AquidifyException
    {
        return new AquidifyException('fake '.$code, $status, $code, 'fake');
    }

    /** @return list<array{domain: string, input: string, locale: string, options: array<string, mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @param (callable(array{domain: string, input: string, locale: string, options: array<string, mixed>}): bool)|null $filter */
    public function assertInterpreted(?callable $filter = null): void
    {
        $matching = $filter === null ? $this->calls : array_filter($this->calls, $filter);
        Assert::assertNotEmpty($matching, 'Expected an Aquidify interpretation that was not requested.');
    }

    public function assertNothingInterpreted(): void
    {
        Assert::assertSame([], $this->calls, 'Expected no Aquidify interpretation, got '.count($this->calls).'.');
    }
}
