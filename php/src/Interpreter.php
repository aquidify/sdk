<?php

declare(strict_types=1);

namespace Aquidify;

/**
 * What an app depends on: the real Client in production, Testing\FakeClient in tests.
 */
interface Interpreter
{
    /**
     * @param  array{parser_version?: string, schema_version?: string, idempotency_key?: string}  $options
     * @return array{request_id: string, domain: string, parser_version: string, schema_version: string, interpretation: array<string, mixed>, clarification: ?array<string, mixed>, meta: array<string, mixed>}
     *
     * @throws AquidifyException
     */
    public function interpret(string $domain, string $input, string $locale, array $options = []): array;
}
