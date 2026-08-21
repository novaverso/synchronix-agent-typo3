<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Middleware;

use Novaverso\SynchronixAgentTypo3\AgentFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;

/**
 * The agent's entry point on a TYPO3 site (protocol section 3).
 *
 * Registered in the `frontend` stack **before** `typo3/cms-frontend/site`, so it
 * needs neither a page tree nor a configured site - a fresh installation with
 * nothing in it can still be paired and queried.
 *
 * Everything of substance lives in the core: this class recognises the path,
 * hands over method, headers and body, and turns the answer into a PSR-7
 * response. Nothing here may reimplement a protocol rule.
 */
final class AgentMiddleware implements MiddlewareInterface
{
    public const PATH = '/synchronix-agent/v1';

    public function __construct(private readonly AgentFactory $agent)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::PATH !== rtrim($request->getUri()->getPath(), '/')) {
            return $handler->handle($request);
        }

        $result = $this->agent->endpoint()->handle(
            $request->getMethod(),
            $this->flattenHeaders($request),
            (string) $request->getBody(),
        );

        // TYPO3's Response takes the **body** first and the status second - not
        // the other way round. Passing the status as the first argument throws
        // "Body must be a string stream resource identifier", which is a
        // confusing way to learn that.
        $response = new Response('php://temp', $result->status, $result->headers);
        $response->getBody()->write($result->body);

        return $response;
    }

    /**
     * PSR-7 keeps a list per header; the core wants one string. Joining with a
     * comma is what RFC 9110 prescribes, and our headers are single-valued
     * anyway.
     *
     * @return array<string, string>
     */
    private function flattenHeaders(ServerRequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return $headers;
    }
}
