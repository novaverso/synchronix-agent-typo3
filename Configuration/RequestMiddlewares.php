<?php

declare(strict_types=1);

use Novaverso\SynchronixAgentTypo3\Middleware\AgentMiddleware;

/**
 * Before `typo3/cms-frontend/site`, so the agent answers without a resolved
 * site or a page tree - a fresh installation must be pairable too.
 */
return [
    'frontend' => [
        'novaverso/synchronix-agent' => [
            'target' => AgentMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
