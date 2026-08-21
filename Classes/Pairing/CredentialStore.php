<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Pairing;

use Novaverso\SynchronixAgent\Http\AgentCredentials;

/**
 * Where the instance secret lives on a TYPO3 site (protocol section 4).
 *
 * In `var/synchronix/credentials.json`, not in the extension configuration.
 * Two reasons: the extension configuration ends up in
 * `config/system/settings.php`, which is version-controlled in most Composer
 * setups - a secret has no business in a repository. And `var/` is writable by
 * design, so the agent can store the secret it receives during pairing without
 * needing write access to the site's configuration.
 *
 * Both directories sit outside the document root in a Composer installation, so
 * neither is downloadable.
 *
 * The var path is injected rather than fetched from Environment:: on the spot.
 * A static call would make this class untestable without booting TYPO3, and the
 * class has nothing to do with TYPO3 beyond knowing where to write.
 */
final class CredentialStore
{
    private const FILE = 'synchronix/credentials.json';

    public function __construct(private readonly string $varPath)
    {
    }

    public function load(): ?AgentCredentials
    {
        $path = $this->path();

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if (!is_string($raw)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $instanceId = $decoded['instanceId'] ?? null;
        $secret = $decoded['secret'] ?? null;

        if (!is_string($instanceId) || !is_string($secret) || '' === $instanceId || '' === $secret) {
            return null;
        }

        return new AgentCredentials($instanceId, $secret);
    }

    public function store(AgentCredentials $credentials): void
    {
        $path = $this->path();
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', $directory));
        }

        $json = json_encode([
            'instanceId' => $credentials->instanceId,
            'secret' => $credentials->secret,
            'pairedAt' => gmdate('c'),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (false === $json || false === @file_put_contents($path, $json)) {
            throw new \RuntimeException(sprintf('Cannot write "%s".', $path));
        }

        // Not readable by other accounts on a shared host. Best effort - some
        // filesystems ignore it, which is why the file also sits outside the
        // document root.
        @chmod($path, 0o600);
    }

    public function forget(): void
    {
        @unlink($this->path());
    }

    public function nonceDirectory(): string
    {
        return $this->varPath.'/synchronix/nonces';
    }

    private function path(): string
    {
        return $this->varPath.'/'.self::FILE;
    }
}
