<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Pairing;

use Novaverso\SynchronixAgent\Http\AgentCredentials;
use Novaverso\SynchronixAgentTypo3\AgentFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Redeems the pairing token at Synchronix (companion protocol, section 4).
 *
 * The agent calls out; Synchronix never calls in for this. That works behind a
 * firewall, and the call is what tells Synchronix which URL the instance sees
 * for itself - not necessarily the one the customer typed.
 */
final class PairingClient
{
    public function __construct(
        private readonly ExtensionConfiguration $configuration,
        private readonly RequestFactory $requestFactory,
        private readonly CredentialStore $credentials,
        private readonly AgentFactory $agent,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * @throws PairingException
     */
    public function pair(): AgentCredentials
    {
        $token = $this->setting('pairingToken');
        $baseUrl = rtrim($this->setting('synchronixUrl'), '/');

        if ('' === $token) {
            throw new PairingException('No pairing token configured. Paste the token from Synchronix into the extension configuration.');
        }

        if ('' === $baseUrl) {
            throw new PairingException('No Synchronix URL configured.');
        }

        if (!str_starts_with($baseUrl, 'https://')) {
            // The secret comes back over this connection exactly once. Without
            // TLS it would be readable on the way.
            throw new PairingException('The Synchronix URL has to use https - the instance secret travels over it.');
        }

        $payload = json_encode([
            'token' => $token,
            'agentVersion' => AgentFactory::AGENT_VERSION,
            'protocol' => $this->agent->protocolVersion(),
            'capabilities' => $this->agent->dispatcher()->capabilities(),
            'cms' => 'typo3',
            'cmsVersion' => $this->agent->identity()->cmsVersion,
            'baseUrl' => $this->ownBaseUrl(),
        ], \JSON_THROW_ON_ERROR);

        try {
            $response = $this->requestFactory->request($baseUrl.'/agent/pair', 'POST', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $payload,
                'timeout' => 20,
            ]);
        } catch (\Throwable $e) {
            throw new PairingException('Synchronix could not be reached: '.$e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();

        if (200 !== $response->getStatusCode()) {
            throw new PairingException(sprintf(
                'Synchronix refused the pairing (HTTP %d): %s',
                $response->getStatusCode(),
                $this->errorFrom($body),
            ));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PairingException('Synchronix sent an answer that is not JSON.', 0, $e);
        }

        if (!is_array($decoded) || true !== ($decoded['ok'] ?? null)) {
            throw new PairingException('Synchronix refused the pairing: '.$this->errorFrom($body));
        }

        $instanceId = $decoded['instanceId'] ?? null;
        $secret = $decoded['secret'] ?? null;

        if (!is_string($instanceId) || !is_string($secret) || '' === $instanceId || '' === $secret) {
            throw new PairingException('Synchronix sent an incomplete answer.');
        }

        $credentials = new AgentCredentials($instanceId, $secret);
        $this->credentials->store($credentials);

        return $credentials;
    }

    /**
     * Best effort. TYPO3 knows its own address only through the site
     * configuration, and on the command line there is no request to ask. A
     * fresh installation without a site returns nothing, and Synchronix then
     * keeps the URL the customer typed.
     */
    private function ownBaseUrl(): ?string
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $base = (string) $site->getBase();

                if ('' !== $base && '/' !== $base) {
                    return rtrim($base, '/');
                }
            }
        } catch (SiteNotFoundException) {
            return null;
        }

        return null;
    }

    private function setting(string $key): string
    {
        try {
            $value = $this->configuration->get('synchronix_agent', $key);
        } catch (\Throwable) {
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }

    private function errorFrom(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && is_string($decoded['error'] ?? null)) {
            return $decoded['error'];
        }

        return mb_substr(trim($body), 0, 200);
    }
}
