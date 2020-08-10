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

namespace AlexanderNitsche\NginxConnector\Tests\Unit\Cache\Backend;

use AlexanderNitsche\NginxConnector\Cache\Backend\NginxCacheBackend;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\HandlerStack as GuzzleHandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case
 */
class NginxCacheBackendTest extends UnitTestCase
{
    protected $resetSingletonInstances = true;

    /**
     * @test
     *
     * @dataProvider successfulNginxPurgeResponseProvider
     *
     * @param int $status Response status code of Nginx cache http request
     */
    public function removeRemovesFromDatabaseIfRemoteNginxPurgeSucceeds(int $status): void
    {
        $entryToRemove = [
            'url' => 'http://example.com/page-1.html', 'identifier' => '1234567890'
        ];

        $nginxCacheResponses = [
            new GuzzleResponse($status),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['getFromDatabase', 'removeFromDatabase']);
        $backend->expects(self::once())->method('getFromDatabase')->willReturn($entryToRemove['url']);
        $backend->expects(self::once())->method('removeFromDatabase')->with($this->equalTo($entryToRemove['identifier']))->willReturn(true);
        $backend->remove($entryToRemove['identifier']);
    }

    /**
     * @return array|int[]
     */
    public function successfulNginxPurgeResponseProvider(): array
    {
        return [
            ['status' => 200],
            ['status' => 204],
            ['status' => 301],
            ['status' => 302],
            ['status' => 303],
        ];
    }

    /**
     * @test
     *
     * @dataProvider failedNginxPurgeResponseProvider
     *
     * @param int $status Response status code of Nginx cache http request
     */
    public function removePreventsRemovalFromDatabaseIfRemoteNginxPurgeFails(int $status): void
    {
        $entryToRemove = [
            'url' => 'http://example.com/page-1.html', 'identifier' => '1234567890'
        ];

        $nginxCacheResponses = [
            new GuzzleResponse($status),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['getFromDatabase', 'removeFromDatabase']);
        $backend->expects(self::once())->method('getFromDatabase')->willReturn($entryToRemove['url']);
        $backend->expects(self::never())->method('removeFromDatabase')->willReturn(false);
        $backend->remove($entryToRemove['identifier']);
    }

    /**
     * @return array|int[]
     */
    public function failedNginxPurgeResponseProvider(): array
    {
        return [
            ['status' => 400],
            ['status' => 404],
            ['status' => 405],
            ['status' => 500],
        ];
    }

    /**
     * @test
     *
     * @dataProvider successfulNginxPurgeResponseProvider
     *
     * @param int $status Response status code of Nginx cache http request
     */
    public function flushFlushesDatabaseIfRemoteNginxPurgeSucceeds(int $status): void
    {
        $nginxCacheResponses = [
            new GuzzleResponse($status),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['getNginxPurgeBaseUrl', 'flushDatabase']);
        $backend->expects(self::any())->method('getNginxPurgeBaseUrl')->willReturn('http://example.com');
        $backend->expects(self::once())->method('flushDatabase');
        $backend->flush();
    }

    /**
     * @test
     */
    public function flushPreventsRemoteNginxPurgeIfNginxBaseUrlCannotBeRetrieved(): void
    {
        $backend = $this->getNginxCacheBackendMock(['getNginxPurgeBaseUrl', 'addEntryToPurge', 'flushDatabase']);
        $backend->expects(self::any())->method('getNginxPurgeBaseUrl')->willReturn('');
        $backend->expects(self::never())->method('addEntryToPurge');
        $backend->expects(self::never())->method('flushDatabase');
        $backend->flush();
    }

    /**
     * @test
     *
     * @dataProvider failedNginxPurgeResponseProvider
     *
     * @param int $status Response status code of Nginx cache http request
     */
    public function flushPreventsFlushOfDatabaseIfRemoteNginxPurgeFails(int $status): void
    {
        $nginxCacheResponses = [
            new GuzzleResponse($status),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['getNginxPurgeBaseUrl', 'flushDatabase']);
        $backend->expects(self::any())->method('getNginxPurgeBaseUrl')->willReturn('http://example.com');
        $backend->expects(self::never())->method('flushDatabase');
        $backend->flush();
    }

    /**
     * @test
     */
    public function flushByTagFlushesDatabaseByTagIfAllRemoteNginxPurgesSucceed(): void
    {
        $entriesByTag = [
            ['url' => 'http://example.com/page-1.html', 'identifier' => '1234567890'],
            ['url' => 'http://example.com/page-2.html', 'identifier' => '1234567891'],
        ];

        $nginxCacheResponses = [
            new GuzzleResponse(200),
            new GuzzleResponse(200),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['findByTags', 'flushDatabaseByTag', 'removeFromDatabase']);
        $backend->method('findByTags')->willReturn($entriesByTag);
        $backend->expects(self::never())->method('removeFromDatabase')->willReturn(false);
        $backend->expects(self::once())->method('flushDatabaseByTag');
        $backend->flushByTag('dummy');
    }

    /**
     * @test
     */
    public function flushByTagRemovesEntriesFromDatabaseOnlyIfRelatedRemoteNginxPurgeSucceeded(): void
    {
        $entriesByTag = [
            ['url' => 'http://example.com/page-1.html', 'identifier' => '1234567890'],
            ['url' => 'http://example.com/page-2.html', 'identifier' => '1234567891'],
            ['url' => 'http://example.com/page-3.html', 'identifier' => '1234567892'],
            ['url' => 'http://example.com/page-4.html', 'identifier' => '1234567893'],
        ];

        $nginxCacheResponses = [
            new GuzzleResponse(200),
            new GuzzleResponse(204),
            new GuzzleResponse(405),
            new GuzzleResponse(500),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['findByTags', 'flushDatabaseByTag', 'removeFromDatabase']);
        $backend->method('findByTags')->willReturn($entriesByTag);
        $backend->expects(self::exactly(2))->method('removeFromDatabase')->with(
            $this->logicalOr(
                $this->equalTo($entriesByTag[0]['identifier']),
                $this->equalTo($entriesByTag[1]['identifier'])
            )
        )->willReturn(true);
        $backend->expects(self::never())->method('flushDatabaseByTag');
        $backend->flushByTag('dummy');
    }

    /**
     * @test
     */
    public function flushByTagsFlushesDatabaseByTagsIfAllRemoteNginxPurgesSucceed(): void
    {
        $entriesByTags = [
            ['url' => 'http://example.com/page-1.html', 'identifier' => '1234567890'],
            ['url' => 'http://example.com/page-2.html', 'identifier' => '1234567891'],
        ];

        $nginxCacheResponses = [
            new GuzzleResponse(200),
            new GuzzleResponse(200),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['findByTags', 'flushDatabaseByTags', 'removeFromDatabase']);
        $backend->method('findByTags')->willReturn($entriesByTags);
        $backend->expects(self::never())->method('removeFromDatabase')->willReturn(false);
        $backend->expects(self::once())->method('flushDatabaseByTags');
        $backend->flushByTags(['dummy1', 'dummy2']);
    }

    /**
     * @test
     */
    public function flushByTagsRemovesEntriesFromDatabaseOnlyIfRelatedRemoteNginxPurgeSucceeded(): void
    {
        $entriesByTags = [
            ['url' => 'http://example.com/page-1.html', 'identifier' => '1234567890'],
            ['url' => 'http://example.com/page-2.html', 'identifier' => '1234567891'],
            ['url' => 'http://example.com/page-3.html', 'identifier' => '1234567892'],
            ['url' => 'http://example.com/page-4.html', 'identifier' => '1234567893'],
        ];

        $nginxCacheResponses = [
            new GuzzleResponse(200),
            new GuzzleResponse(405),
            new GuzzleResponse(204),
            new GuzzleResponse(500),
        ];

        $this->registerHttpClientMock($nginxCacheResponses);

        $backend = $this->getNginxCacheBackendMock(['findByTags', 'flushDatabaseByTags', 'removeFromDatabase']);
        $backend->method('findByTags')->willReturn($entriesByTags);
        $backend->expects(self::exactly(2))->method('removeFromDatabase')->with(
            $this->logicalOr(
                $this->equalTo($entriesByTags[0]['identifier']),
                $this->equalTo($entriesByTags[2]['identifier'])
            )
        )->willReturn(true);
        $backend->expects(self::never())->method('flushDatabaseByTags');
        $backend->flushByTags(['dummy1', 'dummy2']);
    }



    /**
     * @param array $responses
     */
    protected function registerHttpClientMock(array $responses): void
    {
        $transactions = [];
        $mock = new GuzzleMockHandler($responses);
        $handler = GuzzleHandlerStack::create($mock);
        $handler->push(GuzzleMiddleware::history($transactions));
        $guzzleClient = new GuzzleClient(['handler' => $handler]);

        GeneralUtility::addInstance(GuzzleClient::class, $guzzleClient);
    }

    /**
     * @param array $methods
     * @return NginxCacheBackend
     */
    protected function getNginxCacheBackendMock($methods = []) : NginxCacheBackend
    {
        $frontendProphecy = $this->prophesize(FrontendInterface::class);
        $frontendProphecy->getIdentifier()->willReturn('test');

        $backend = $this->getAccessibleMock(
            NginxCacheBackend::class,
            $methods,
            ['Testing'],
            '',
            false
        );
        $backend->setCache($frontendProphecy->reveal());
        $backend->setLogger(new NullLogger());

        return $backend;
    }
}
