<?php

declare(strict_types=1);

namespace Aquidify\Laravel;

use Aquidify\Interpreter;

/**
 * Adds the configured parser/schema versions to every call, so production
 * code cannot forget to pin them. Per-call options still win.
 */
final class PinnedInterpreter implements Interpreter
{
    /** @param array<string, string> $defaults */
    public function __construct(private readonly Interpreter $inner, private readonly array $defaults) {}

    public function interpret(string $domain, string $input, string $locale, array $options = []): array
    {
        return $this->inner->interpret($domain, $input, $locale, $options + $this->defaults);
    }
}
