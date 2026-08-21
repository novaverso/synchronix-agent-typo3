<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Tests\Pairing;

use Novaverso\SynchronixAgent\Http\AgentCredentials;
use Novaverso\SynchronixAgentTypo3\Pairing\CredentialStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the instance secret lives on a TYPO3 site (protocol section 4).
 *
 * Runs without booting TYPO3, which is the point of injecting the var path
 * instead of calling Environment:: inside the class.
 */
final class CredentialStoreTest extends TestCase
{
    private string $varPath;

    protected function setUp(): void
    {
        $this->varPath = sys_get_temp_dir().'/synchronix-var-'.bin2hex(random_bytes(6));
        mkdir($this->varPath, 0o770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->varPath.'/synchronix/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->varPath.'/synchronix');
        @rmdir($this->varPath);
    }

    /**
     * An agent that was never paired has no credentials, and the endpoint then
     * answers everything with 404 - the site is indistinguishable from one
     * without an agent.
     */
    public function testAnUnpairedSiteHasNoCredentials(): void
    {
        self::assertNull($this->store()->load());
    }

    public function testStoredCredentialsComeBack(): void
    {
        $store = $this->store();
        $store->store(new AgentCredentials('inst_9', str_repeat('a', 64)));

        $loaded = $store->load();

        self::assertNotNull($loaded);
        self::assertSame('inst_9', $loaded->instanceId);
        self::assertSame(str_repeat('a', 64), $loaded->secret);
    }

    /**
     * The directory below var/ is created on demand: a fresh site has nothing
     * prepared, and pairing has to work on the first attempt.
     */
    public function testDirectoryIsCreatedOnDemand(): void
    {
        $this->store()->store(new AgentCredentials('inst_9', 'secret'));

        self::assertDirectoryExists($this->varPath.'/synchronix');
    }

    /**
     * Best effort on a shared host, but worth asserting: the file holds a
     * secret and has no business being readable by other accounts.
     */
    public function testTheFileIsNotWorldReadable(): void
    {
        $this->store()->store(new AgentCredentials('inst_9', 'secret'));

        $mode = fileperms($this->varPath.'/synchronix/credentials.json') & 0o777;

        self::assertSame(0, $mode & 0o077, sprintf('Expected no group or other permissions, got 0%o.', $mode));
    }

    public function testPairingAgainReplacesTheCredentials(): void
    {
        $store = $this->store();
        $store->store(new AgentCredentials('inst_9', 'first'));
        $store->store(new AgentCredentials('inst_10', 'second'));

        $loaded = $store->load();

        self::assertNotNull($loaded);
        self::assertSame('inst_10', $loaded->instanceId);
        self::assertSame('second', $loaded->secret);
    }

    public function testForgettingUnpairsTheSite(): void
    {
        $store = $this->store();
        $store->store(new AgentCredentials('inst_9', 'secret'));
        $store->forget();

        self::assertNull($store->load());
    }

    /**
     * A damaged file must read as "not paired", not as half-valid credentials -
     * an agent with a wrong secret would be a site nobody can reach and nobody
     * can diagnose.
     */
    #[DataProvider('damagedFiles')]
    public function testDamagedFilesReadAsUnpaired(string $contents): void
    {
        $store = $this->store();
        mkdir($this->varPath.'/synchronix', 0o770, true);
        file_put_contents($this->varPath.'/synchronix/credentials.json', $contents);

        self::assertNull($store->load());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedFiles(): iterable
    {
        yield 'empty' => [''];
        yield 'not JSON' => ['nope'];
        yield 'JSON array' => ['[1,2,3]'];
        yield 'instance id missing' => ['{"secret":"abc"}'];
        yield 'secret missing' => ['{"instanceId":"inst_9"}'];
        yield 'secret empty' => ['{"instanceId":"inst_9","secret":""}'];
        yield 'secret not a string' => ['{"instanceId":"inst_9","secret":123}'];
    }

    public function testNonceDirectorySitsBelowVar(): void
    {
        self::assertSame($this->varPath.'/synchronix/nonces', $this->store()->nonceDirectory());
    }

    private function store(): CredentialStore
    {
        return new CredentialStore($this->varPath);
    }
}
