<?php

declare(strict_types=1);

namespace Aquidify\Laravel;

use Aquidify\Client;
use Aquidify\Interpreter;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered. Type-hint Aquidify\Interpreter (or use the Aquidify facade);
 * tests swap it with Aquidify::fake().
 */
final class AquidifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/aquidify.php', 'aquidify');

        $this->app->singleton(Interpreter::class, function ($app): Interpreter {
            $config = $app['config']['aquidify'];
            $client = new Client($config['key'] ?: null, $config['url'], (int) $config['timeout']);

            return new PinnedInterpreter($client, array_filter([
                'parser_version' => $config['parser_version'] ?? null,
                'schema_version' => $config['schema_version'] ?? null,
            ]));
        });
        $this->app->alias(Interpreter::class, 'aquidify');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/aquidify.php' => config_path('aquidify.php')], 'aquidify-config');
        }
    }
}
