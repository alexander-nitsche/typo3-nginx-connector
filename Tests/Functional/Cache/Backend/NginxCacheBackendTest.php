<?php

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

namespace AlexanderNitsche\NginxConnector\Tests\Functional\Cache\Backend;

use Doctrine\DBAL\DBALException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Exception;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * These tests assure that the TYPO3 core sends cache headers as expected and required by the Nginx Cache,
 * based on TypoScript configuration
 *
 * config {
 *   sendCacheHeaders = 1
 *   sendCacheHeaders_onlyWhenLoginDeniedInBranch = 1
 * }
 */
class NginxCacheBackendTest extends FunctionalTestCase
{
    /**
     * @var array Have nginx_connector loaded
     */
    protected $testExtensionsToLoad = [
        'typo3conf/ext/nginx_connector',
    ];

    /**
     * Sets up this test case.
     *
     * @throws Exception
     * @throws DBALException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->importDataSet(__DIR__ . '/DataSet/DefaultPages.xml');
        $this->setUpFrontendRootPage(
            1,
            ['EXT:nginx_connector/Tests/Functional/Cache/Backend/DataSet/DefaultRendering.typoscript']
        );
        $this->setUpFrontendSite(1);
    }

    /**
     * Create a simple site config for the tests that
     * call a frontend page.
     *
     * @param int $pageId
     */
    protected function setUpFrontendSite(int $pageId)
    {
        $configuration = [
            'rootPageId' => $pageId,
            'base' => '/',
            'websiteTitle' => '',
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => '0',
                    'base' => '/',
                    'typo3Language' => 'default',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'websiteTitle' => '',
                    'navigationTitle' => '',
                    'hreflang' => '',
                    'direction' => '',
                    'flag' => 'us',
                ]
            ],
            'errorHandling' => [],
            'routes' => [],
        ];
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/testing/');
        $yamlFileContents = Yaml::dump($configuration, 99, 2);
        $fileName = $this->instancePath . '/typo3conf/sites/testing/config.yaml';
        GeneralUtility::writeFile($fileName, $yamlFileContents);
        // Ensure that no other site configuration was cached before
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('core');
        if ($cache->has('sites-configuration')) {
            $cache->remove('sites-configuration');
        }
    }

    /**
     * @test
     */
    public function pageWithoutUserAccessReturnsCacheHeaderWithDefaultLifetime()
    {
        $response = $this->executeFrontendRequest(
            (new InternalRequest())->withPageId(2)
        );
        $headers = $response->getHeaders();
        $this->assertEquals('max-age=86400', $headers['Cache-Control'][0]);
    }

    /**
     * @test
     */
    public function pageWithoutUserAccessReturnsCacheHeaderWithCustomLifetime()
    {
        $response = $this->executeFrontendRequest(
            (new InternalRequest())->withPageId(3)
        );
        $headers = $response->getHeaders();
        $this->assertEquals('max-age=300', $headers['Cache-Control'][0]);
    }

    /**
     * @test
     */
    public function pageWithUserAccessReturnsCacheHeaderWithNoLifetime()
    {
        $response = $this->executeFrontendRequest(
            (new InternalRequest())->withPageId(4)
        );
        $headers = $response->getHeaders();
        $this->assertEquals('private, no-store', $headers['Cache-Control'][0]);
    }

    /**
     * @test
     */
    public function pageWithoutUserAccessButWithNonCacheablePluginReturnsCacheHeaderWithNoLifetime()
    {
        $this->addTypoScriptToTemplateRecord(
            1, 'page.20 = USER_INT'
        );
        $response = $this->executeFrontendRequest(
            (new InternalRequest())->withPageId(2)
        );
        $headers = $response->getHeaders();
        $this->assertEquals('private, no-store', $headers['Cache-Control'][0]);
    }

    /**
     * @test
     */
    public function pageWithoutUserAccessAndWithCacheablePluginReturnsCacheHeaderWithDefaultLifetime()
    {
        $this->addTypoScriptToTemplateRecord(
            1, 'page.20 = USER'
        );
        $response = $this->executeFrontendRequest(
            (new InternalRequest())->withPageId(2)
        );
        $headers = $response->getHeaders();
        $this->assertEquals('max-age=86400', $headers['Cache-Control'][0]);
    }

    /**
     * @test
     */
    public function pageWithoutUserAccessButWithGlobalNoCacheReturnsCacheHeaderWithNoLifetime()
    {
        $this->addTypoScriptToTemplateRecord(
            1, 'config.no_cache=1'
        );
        $response = $this->executeFrontendRequest(
            (new InternalRequest())->withPageId(2)
        );
        $headers = $response->getHeaders();
        $this->assertEquals('private, no-store', $headers['Cache-Control'][0]);
    }
}
