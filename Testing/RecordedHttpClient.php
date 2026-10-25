<?php

namespace Omnibus\Testing;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A carrier's recorded answers in place of its API: no request leaves the
 * process. Each route is "METHOD fragment" or "fragment" (any method), matched
 * when the request's URL contains the fragment - or, for "body:fragment", when
 * its body does (a SOAP operation, say); the first route declared that matches
 * answers.
 *
 *   new RecordedHttpClient([
 *       'POST /security/v1/oauth/token' => ['access_token' => 'tok', 'expires_in' => 14399],
 *       '/api/rating/'                  => file_get_contents(__DIR__.'/recorded/rate.json'),
 *       'GET /label/'                   => RecordedHttpClient::answer('%PDF-1.4', 200, ['content-type' => 'application/pdf']),
 *       '/track/'                       => fn (string $method, string $url, array $options) => [...],
 *       'POST body:<TrackRequest'       => '<soap:Envelope>...</soap:Envelope>',
 *   ]);
 *
 * An answer is an array (sent as JSON), a string (the body as it is), a
 * MockResponse, or a closure of the request returning one of those. A request
 * no route matches fails at the transport (the carrier sees a network error)
 * and is listed by unanswered(), so a test can say which route is missing.
 *
 * failing() gives the same client answering every request with one failure -
 * an HTTP status, a network error, a body no carrier can read - except the
 * routes kept (a login, say), for checking how a carrier reports its errors.
 *
 * Requires symfony/http-client (a carrier calling an API has it already).
 */
final class RecordedHttpClient implements HttpClientInterface
{
    private MockHttpClient $client;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<string> */
    private array $unanswered = [];

    /**
     * @param array<string, mixed> $answers route => answer
     * @param list<string>         $kept    routes that keep their answer while failing
     */
    public function __construct(
        private readonly array $answers = [],
        private readonly ?\Closure $failure = null,
        private readonly array $kept = [],
    ) {
        $this->client = new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->respond($method, $url, $options));
    }

    /**
     * A response with its status and headers, for an answer that is not a plain 200.
     *
     * @param array<mixed>|string           $body
     * @param array<string, string|string[]> $headers
     */
    public static function answer(array|string $body, int $status = 200, array $headers = []): MockResponse
    {
        if (\is_array($body)) {
            $body = json_encode($body, \JSON_THROW_ON_ERROR);
            $headers += ['content-type' => 'application/json'];
        }

        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
    }

    /** The answer of a network that fails: no status, no body. */
    public static function networkError(string $message = 'Connection refused (recorded)'): MockResponse
    {
        return new MockResponse('', ['error' => $message]);
    }

    /**
     * The same routes, every request but those of $kept answered with $failure.
     *
     * @param list<string> $kept
     */
    public function failing(MockResponse $failure, array $kept = []): self
    {
        return new self($this->answers, static fn (): MockResponse => clone $failure, $kept);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    /** @return list<array{method: string, url: string, options: array<string, mixed>}> every request made, in order */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return list<string> "METHOD url" of each request no route answered */
    public function unanswered(): array
    {
        return $this->unanswered;
    }

    /** @param array<string, mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $route = $this->route($method, $url, \is_string($options['body'] ?? null) ? $options['body'] : '');

        if (null !== $this->failure && (null === $route || !\in_array($route, $this->kept, true))) {
            return ($this->failure)();
        }
        if (null === $route) {
            $this->unanswered[] = $method.' '.$url;

            return self::networkError(\sprintf('No recorded answer for %s %s.', $method, $url));
        }

        $answer = $this->answers[$route];
        if ($answer instanceof \Closure) {
            $answer = $answer($method, $url, $options);
        }

        return match (true) {
            $answer instanceof MockResponse => clone $answer,
            \is_array($answer) => self::answer($answer),
            default => new MockResponse((string) $answer),
        };
    }

    private function route(string $method, string $url, string $body): ?string
    {
        foreach (array_keys($this->answers) as $route) {
            $route = (string) $route;
            $fragment = $route;
            if (preg_match('/^([A-Z]+) (.+)$/', $route, $m)) {
                if ($m[1] !== strtoupper($method)) {
                    continue;
                }
                $fragment = $m[2];
            }
            if (str_starts_with($fragment, 'body:') ? str_contains($body, substr($fragment, 5)) : str_contains($url, $fragment)) {
                return $route;
            }
        }

        return null;
    }
}
