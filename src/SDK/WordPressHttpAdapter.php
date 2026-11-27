<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle HTTP handler that executes PSR-7 requests via WordPress's HTTP API (wp_remote_request).
 */
class WordPressHttpAdapter
{
    /**
     * Default response timeout (in seconds).
     */
    public const DEFAULT_TIMEOUT = 10;

    /**
     * Optional observer receiving safe HTTP telemetry after each request:
     * array{method: string, path: string, status: int, ms: int, idempotency_key: string, error: ?string}.
     *
     * @var (callable(array<string, mixed>): void)|null
     */
    private $observer;

    /**
     * @param (callable(array<string, mixed>): void)|null $observer
     */
    public function __construct(?callable $observer = null)
    {
        $this->observer = $observer;
    }

    /**
     * Create a Guzzle client backed by WordPress's HTTP API.
     *
     * @param (callable(array<string, mixed>): void)|null $observer
     */
    public static function createGuzzleClient(?callable $observer = null): ClientInterface
    {
        $stack = HandlerStack::create(new self($observer));

        return new Client([
            'handler' => $stack,
            'timeout' => self::DEFAULT_TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * Execute a PSR-7 request via wp_remote_request().
     *
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = (string) $request->getBody();
        $timeout = isset($options['timeout']) && is_numeric($options['timeout'])
            ? (float) $options['timeout']
            : self::DEFAULT_TIMEOUT;

        $args = [
            'method' => $request->getMethod(),
            'headers' => $headers,
            'sslverify' => true,
            'timeout' => $timeout,
        ];

        if ($body !== '') {
            $args['body'] = $body;
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        if ($userAgent !== '') {
            $args['user-agent'] = $userAgent;
        }

        $startedAt = microtime(true);
        $response = wp_remote_request((string) $request->getUri(), $args);

        if (is_wp_error($response)) {
            $errorMessage = $response->get_error_message();
            $this->observe($request, 0, $startedAt, $errorMessage);

            return Create::rejectionFor(
                new ConnectException($errorMessage, $request)
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawHeaders = wp_remote_retrieve_headers($response);
        $responseHeaders = $this->normalizeHeaders($rawHeaders);
        $responseBody = (string) wp_remote_retrieve_body($response);

        $this->observe($request, $statusCode, $startedAt, null);

        return Create::promiseFor(new Response($statusCode, $responseHeaders, $responseBody));
    }

    private function observe(RequestInterface $request, int $statusCode, float $startedAt, ?string $error): void
    {
        if ($this->observer === null) {
            return;
        }

        $fields = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $statusCode,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
        if ($idempotencyKey !== '') {
            $fields['idempotency_key'] = $idempotencyKey;
        }

        if ($error !== null && $error !== '') {
            $fields['error'] = $error;
        }

        ($this->observer)($fields);
    }

    public function versionString(): string
    {
        global $wp_version;
        return 'WordPress/' . ($wp_version ?? 'Unknown');
    }

    /**
     * @param mixed $rawHeaders
     * @return array<string, string|array<string>>
     */
    private function normalizeHeaders($rawHeaders): array
    {
        if (is_object($rawHeaders) && method_exists($rawHeaders, 'getAll')) {
            $rawHeaders = $rawHeaders->getAll();
        }

        if (!is_iterable($rawHeaders)) {
            return [];
        }

        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $headers[$name] = array_map('strval', $value);
            } else {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
