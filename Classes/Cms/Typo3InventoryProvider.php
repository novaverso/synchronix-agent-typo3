<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Cms;

use Novaverso\SynchronixAgent\Cms\InventoryProvider;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * What is installed on this TYPO3 site (protocol section 8).
 *
 * Reports **only the installed state**. Whether something is outdated is
 * Synchronix's job, decided against Packagist - `composer outdated` on a
 * customer's site is unreliable to impossible (memory, runtime, often no
 * network), and centrally it is implemented once instead of on every instance.
 *
 * Both lists are reported on purpose: the extension key and the Composer name
 * are not the same thing, and we need the key for display and the Composer name
 * for the update check.
 */
final class Typo3InventoryProvider implements InventoryProvider
{
    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly Typo3Version $version,
        private readonly string $projectPath,
        private readonly bool $composerMode,
    ) {
    }

    public function collect(): array
    {
        return [
            'cms' => [
                'type' => 'typo3',
                'version' => $this->version->getVersion(),
            ],
            'php' => [
                'version' => \PHP_VERSION,
            ],
            'composerMode' => $this->composerMode,
            'packages' => $this->packagesFromLock(),
            'extensions' => $this->extensions(),
        ];
    }

    /**
     * @return list<array{key: string, version: string, active: bool, composerName: string|null}>
     */
    private function extensions(): array
    {
        $extensions = [];

        foreach ($this->packageManager->getActivePackages() as $package) {
            $meta = $package->getPackageMetaData();

            $extensions[] = [
                'key' => $package->getPackageKey(),
                'version' => $meta->getVersion(),
                'active' => true,
                'composerName' => $package->getValueFromComposerManifest('name') ?? null,
            ];
        }

        usort($extensions, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $extensions;
    }

    /**
     * Read straight from composer.lock rather than asking Composer: running
     * Composer from inside a request is not something a customer's host will
     * tolerate, and the lock file already holds the exact answer.
     *
     * @return list<array{name: string, version: string, type: string}>
     */
    private function packagesFromLock(): array
    {
        $path = $this->projectPath.'/composer.lock';

        if (!is_file($path)) {
            // Legacy installation, or a stripped deployment. Not an error: the
            // extension list above still works, and Synchronix has to cope with
            // an agent reporting less (protocol section 6).
            return [];
        }

        $raw = @file_get_contents($path);

        if (!is_string($raw)) {
            return [];
        }

        try {
            /** @var mixed $lock */
            $lock = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($lock)) {
            return [];
        }

        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($lock[$section] ?? []) as $package) {
                if (!is_array($package) || !isset($package['name'], $package['version'])) {
                    continue;
                }

                $packages[] = [
                    'name' => (string) $package['name'],
                    'version' => (string) $package['version'],
                    'type' => (string) ($package['type'] ?? 'library'),
                ];
            }
        }

        usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $packages;
    }
}
