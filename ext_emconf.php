<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Nginx Connector',
    'description' => 'Nginx cache connector for TYPO3',
    'category' => 'misc',
    'author' => 'Alexander Nitsche',
    'author_email' => 'typo3@alexandernitsche.com',
    'version' => '0.3',
    'state' => 'alpha',
    'clearCacheOnLoad' => true,
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-10.4.99',
            'php' => '7.3.0-7.3.99',
        ],
        'conflicts' => [
            'nginx_cache' => '',
        ],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'AlexanderNitsche\\NginxConnector\\' => 'Classes'
        ]
    ],
];
