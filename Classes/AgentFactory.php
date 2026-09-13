<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3;

use Novaverso\SynchronixAgent\Handler\InventoryHandler;
use Novaverso\SynchronixAgent\Handler\PingHandler;
use Novaverso\SynchronixAgent\Http\AgentEndpoint;
use Novaverso\SynchronixAgent\Protocol\AgentIdentity;
use Novaverso\SynchronixAgent\Protocol\Dispatcher;
use Novaverso\SynchronixAgent\Protocol\Request;
use Novaverso\SynchronixAgent\Signature\FilesystemNonceStore;
use Novaverso\SynchronixAgent\Signature\Signer;
use Novaverso\SynchronixAgent\Signature\SystemClock;
use Novaverso\SynchronixAgent\Signature\Verifier;
use Novaverso\SynchronixAgentTypo3\Cms\Typo3InventoryProvider;
use Novaverso\SynchronixAgentTypo3\Pairing\CredentialStore;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Builds the agent. One place, because the middleware and the pairing command
 * must report the **same** capabilities - a second list would eventually
 * disagree with the first, and Synchronix greys out buttons based on it.
 */
final class AgentFactory
{
    public const AGENT_VERSION = '1.0.1';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly PackageManager $packageManager,
        private readonly Typo3Version $version,
    ) {
    }

    public function dispatcher(): Dispatcher
    {
        $clock = new SystemClock();

        return new Dispatcher([
            new PingHandler($clock),
            new InventoryHandler(new Typo3InventoryProvider(
                $this->packageManager,
                $this->version,
                Environment::getProjectPath(),
                Environment::isComposerMode(),
            )),
        ]);
    }

    public function endpoint(): AgentEndpoint
    {
        $clock = new SystemClock();

        return new AgentEndpoint(
            $this->dispatcher(),
            new Verifier($clock, new FilesystemNonceStore($this->credentials->nonceDirectory(), $clock)),
            new Signer($clock),
            $clock,
            $this->identity(),
            $this->credentials->load(),
        );
    }

    public function identity(): AgentIdentity
    {
        return new AgentIdentity(self::AGENT_VERSION, 'typo3', $this->version->getVersion());
    }

    public function protocolVersion(): int
    {
        return Request::VERSION;
    }
}
