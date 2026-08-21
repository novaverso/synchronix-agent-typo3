<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Middleware;

use Novaverso\SynchronixAgent\Handler\InventoryHandler;
use Novaverso\SynchronixAgent\Handler\PingHandler;
use Novaverso\SynchronixAgent\Http\AgentEndpoint;
use Novaverso\SynchronixAgent\Protocol\AgentIdentity;
use Novaverso\SynchronixAgent\Protocol\Dispatcher;
use Novaverso\SynchronixAgent\Signature\FilesystemNonceStore;
use Novaverso\SynchronixAgent\Signature\Signer;
use Novaverso\SynchronixAgent\Signature\SystemClock;
use Novaverso\SynchronixAgent\Signature\Verifier;
use Novaverso\SynchronixAgentTypo3\Cms\Typo3InventoryProvider;
use Novaverso\SynchronixAgentTypo3\Pairing\CredentialStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

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

    public const AGENT_VERSION = '1.0.0';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly PackageManager $packageManager,
        private readonly Typo3Version $version,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::PATH !== rtrim($request->getUri()->getPath(), '/')) {
            return $handler->handle($request);
        }

        $result = $this->endpoint()->handle(
            $request->getMethod(),
            $this->flattenHeaders($request),
            (string) $request->getBody(),
        );

        $response = new Response($result->status);

        foreach ($result->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write($result->body);

        return $response;
    }

    private function endpoint(): AgentEndpoint
    {
        $clock = new SystemClock();

        return new AgentEndpoint(
            new Dispatcher([
                new PingHandler($clock),
                new InventoryHandler(new Typo3InventoryProvider(
                    $this->packageManager,
                    $this->version,
                    Environment::getProjectPath(),
                    Environment::isComposerMode(),
                )),
            ]),
            new Verifier($clock, new FilesystemNonceStore($this->credentials->nonceDirectory(), $clock)),
            new Signer($clock),
            $clock,
            new AgentIdentity(self::AGENT_VERSION, 'typo3', $this->version->getVersion()),
            $this->credentials->load(),
        );
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
