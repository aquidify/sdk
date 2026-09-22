<?php

declare(strict_types=1);

namespace Aquidify\Tests\Laravel;

use Aquidify\AquidifyException;
use Aquidify\Interpreter;
use Aquidify\Laravel\AquidifyServiceProvider;
use Aquidify\Laravel\Facades\Aquidify;
use Aquidify\Laravel\PinnedInterpreter;
use Aquidify\Testing\FakeClient;
use Orchestra\Testbench\TestCase;

final class AdapterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AquidifyServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Aquidify' => Aquidify::class];
    }

    public function test_config_is_merged_with_pinned_versions(): void
    {
        $this->assertSame('https://api.aquidify.com', config('aquidify.url'));
        $this->assertSame('1.0.0', config('aquidify.parser_version'));
    }

    public function test_resolves_a_pinned_client_when_a_key_is_configured(): void
    {
        config(['aquidify.key' => 'k']);

        $this->assertInstanceOf(PinnedInterpreter::class, $this->app->make(Interpreter::class));
    }

    public function test_resolving_without_a_key_fails_loudly(): void
    {
        config(['aquidify.key' => null]);
        putenv('AQUIDIFY_API_KEY');

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(Interpreter::class);
    }

    public function test_fake_replaces_facade_and_injected_client(): void
    {
        $fake = Aquidify::fake([
            'skladišče' => FakeClient::interpretation(['intents' => [['roles' => [['value' => 'warehouse']]]]]),
        ]);

        $viaFacade = Aquidify::interpret('hiring.candidate', 'Delo v skladišče', 'sl');
        $viaContainer = $this->app->make(Interpreter::class)->interpret('hiring.candidate', 'Ljubljana', 'sl');

        $this->assertSame('warehouse', $viaFacade['interpretation']['intents'][0]['roles'][0]['value']);
        $this->assertSame([], $viaContainer['interpretation']['intents']);
        $this->assertCount(2, $fake->calls());
        Aquidify::assertInterpreted(fn (array $call) => $call['input'] === 'Ljubljana');
    }

    public function test_fake_throws_queued_errors(): void
    {
        Aquidify::fake(['*' => FakeClient::error('model_unavailable')]);

        try {
            Aquidify::interpret('hiring.candidate', 'x', 'sl');
            $this->fail('expected AquidifyException');
        } catch (AquidifyException $e) {
            $this->assertSame('model_unavailable', $e->errorCode);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_pinned_versions_are_added_but_per_call_options_win(): void
    {
        $inner = new FakeClient;
        $pinned = new PinnedInterpreter($inner, ['parser_version' => '1.0.0', 'schema_version' => '1.0.0']);

        $pinned->interpret('hiring.candidate', 'a', 'sl');
        $pinned->interpret('hiring.candidate', 'b', 'sl', ['parser_version' => '2.0.0']);

        $this->assertSame(['parser_version' => '1.0.0', 'schema_version' => '1.0.0'], $inner->calls()[0]['options']);
        $this->assertSame('2.0.0', $inner->calls()[1]['options']['parser_version']);
    }
}
