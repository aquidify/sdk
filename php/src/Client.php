<?php

declare(strict_types=1);

namespace Aquidify;

/**
 * Aquidify API client.
 *
 *     $aq = new Aquidify\Client(getenv('AQUIDIFY_API_KEY'));
 *     $r  = $aq->interpret('hiring.candidate', 'Iščem delo v skladišču v Ljubljani, brez nočnih.', 'sl-SI');
 *     $r['interpretation']['intents'][0]['roles'][0]['value']; // "warehouse"
 */
final class Client implements Interpreter
{
    public const VERSION = '0.3.0';

    private readonly string $apiKey;

    public function __construct(
        ?string $apiKey = null,
        private readonly string $baseUrl = 'https://api.aquidify.com',
        private readonly int $timeoutSeconds = 60,
    ) {
        $key = $apiKey ?? (getenv('AQUIDIFY_API_KEY') ?: '');
        if ($key === '') {
            throw new \InvalidArgumentException('Aquidify API key missing: pass it or set AQUIDIFY_API_KEY.');
        }
        $this->apiKey = $key;
    }

    /**
     * Interpret free text into structured intent.
     *
     * @param  array{parser_version?: string, schema_version?: string, idempotency_key?: string}  $options
     * @return array{request_id: string, domain: string, parser_version: string, schema_version: string, interpretation: array<string, mixed>, clarification: ?array<string, mixed>, meta: array<string, mixed>}
     *
     * @throws AquidifyException on any non-2xx response or transport failure
     */
    public function interpret(string $domain, string $input, string $locale, array $options = []): array
    {
        $body = ['domain' => $domain, 'input' => $input, 'locale' => $locale];
        foreach (['parser_version', 'schema_version'] as $k) {
            if (isset($options[$k])) {
                $body[$k] = $options[$k];
            }
        }
        $headers = [];
        if (isset($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: '.$options['idempotency_key'];
        }

        return $this->request('POST', '/v1/interpret', $body, $headers);
    }

    /**
     * Register a task version: what to extract from free text, for your own use case.
     * Sending the same definition again is a no-op; a changed definition needs a new version.
     *
     *     $aq->putTask('support.ticket@1.0.0', [
     *         'instructions' => 'Classify customer support emails.',
     *         'schema' => ['type' => 'object', 'properties' => [
     *             'category' => ['enum' => ['billing', 'bug', 'refund']],
     *             'order_id' => ['type' => 'string'],
     *         ]],
     *         'examples' => [['input' => 'Charged twice for #1200', 'output' => ['category' => 'billing', 'order_id' => '1200']]],
     *     ]);
     *     $aq->interpret('support.ticket@1.0.0', $emailBody, 'en')['interpretation']['fields'];
     *
     * @param  array{instructions: string, schema: array<string, mixed>, description?: string, examples?: list<array{input: string, output: array<string, mixed>}>}  $definition
     * @return array<string, mixed> the task, its parser_version and output_schema
     *
     * @throws AquidifyException invalid_task, task_exists, task_limit, ...
     */
    public function putTask(string $id, array $definition): array
    {
        return $this->request('PUT', '/v1/tasks/'.rawurlencode($id), $definition, []);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AquidifyException not_found when this API key has no such task
     */
    public function getTask(string $id): array
    {
        return $this->request('GET', '/v1/tasks/'.rawurlencode($id), null, []);
    }

    /**
     * @return list<string> task ids registered with this API key
     *
     * @throws AquidifyException
     */
    public function listTasks(): array
    {
        return $this->request('GET', '/v1/tasks', null, [])['tasks'];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $headers): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/').$path);
        $responseHeaders = [];
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: aquidify-sdk-php/'.self::VERSION,
                ...$headers,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);

        if ($raw === false) {
            throw new AquidifyException('Aquidify request failed: '.$transportError, 0, 'network');
        }

        $data = json_decode((string) $raw, true);
        if ($status >= 200 && $status < 300 && is_array($data)) {
            return $data;
        }

        $retryAfter = isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;
        throw new AquidifyException(
            $data['error']['message'] ?? 'Aquidify returned HTTP '.$status,
            $status,
            $data['error']['code'] ?? 'unknown',
            $data['request_id'] ?? ($responseHeaders['x-request-id'] ?? null),
            $retryAfter,
        );
    }
}
