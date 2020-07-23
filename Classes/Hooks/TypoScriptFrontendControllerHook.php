<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace AlexanderNitsche\NginxConnector\Hooks;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class TypoScriptFrontendControllerHook
{
    /**
     * @var CacheManager
     */
    private $cacheManager;

    /**
     * @param CacheManager $cacheManager
     */
    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * Hook for page cache post processing
     *
     * @param TypoScriptFrontendController $tsfe
     * @param int $timeOutTime
     */
    public function insertPageIncache(TypoScriptFrontendController &$tsfe, $timeOutTime): void
    {
        $requestUrl = GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL');
        $pageCache = $this->cacheManager->getCache('nginx_connector');

        $pageCache->set(md5($requestUrl), $requestUrl, $tsfe->getPageCacheTags(), $timeOutTime);
    }
}
