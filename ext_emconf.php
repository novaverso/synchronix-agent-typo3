<?php

/**
 * Kept even though only Composer mode is supported: TYPO3 still reads this file
 * for the extension list in the backend, and leaving it out makes the extension
 * show up without a title or description.
 */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Synchronix Agent',
    'description' => 'Companion agent for Synchronix - reports the installed state and carries out commands.',
    'category' => 'services',
    'author' => 'Novaverso',
    'state' => 'beta',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
