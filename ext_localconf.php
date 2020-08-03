<?php
defined('TYPO3_MODE') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nginx_connector'] = [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \AlexanderNitsche\NginxConnector\Cache\Backend\NginxCacheBackend::class,
    'groups' => [
        'pages',
        'all'
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['insertPageIncache'][] =
    \AlexanderNitsche\NginxConnector\Hooks\TypoScriptFrontendControllerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['pageLoadedFromCache'][] =
    \AlexanderNitsche\NginxConnector\Hooks\TypoScriptFrontendControllerHook::class . '->handlePageLoadedFromCache';