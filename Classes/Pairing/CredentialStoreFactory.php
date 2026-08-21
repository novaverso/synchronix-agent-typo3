<?php

declare(strict_types=1);

namespace Novaverso\SynchronixAgentTypo3\Pairing;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Supplies the var path to the store.
 *
 * A factory rather than a DI expression, because Environment is a static
 * utility class and not a service - `service("...Environment")` would fail at
 * runtime. This is the single place where the store touches TYPO3, which is
 * what keeps the store itself testable.
 */
final class CredentialStoreFactory
{
    public static function create(): CredentialStore
    {
        return new CredentialStore(Environment::getVarPath());
    }
}
