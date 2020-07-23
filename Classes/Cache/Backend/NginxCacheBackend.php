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

namespace AlexanderNitsche\NginxConnector\Cache\Backend;

use Doctrine\DBAL\FetchMode;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use TYPO3\CMS\Core\Cache\Backend\TransientBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Cache\Exception\InvalidDataException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A caching backend which stores cache entries in database tables
 */
class NginxCacheBackend extends Typo3DatabaseBackend implements TransientBackendInterface
{
    /**
     * @var string
     */
    protected $baseUrl = null;

    /**
     * @var array
     */
    protected $entriesToPurge = [];

    /**
     * @var bool
     */
    protected $purgeFailed = false;

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     * @return bool TRUE if (at least) an entry could be removed or FALSE if no entry was found
     */
    public function remove($entryIdentifier): bool
    {
        $url = $this->getFromDatabase($entryIdentifier);

        if ($url === false) {
            return false;
        }

        $this->addEntryToPurge($url, $entryIdentifier);
        $this->purge();
        if ($this->hasPurgeFailed()) {
            return false;
        }

        return $this->removeFromDatabase($entryIdentifier);
    }

    /**
     * @param string $entryIdentifier
     * @return mixed The cache entry's data as a string or FALSE if the cache entry could not be loaded
     */
    protected function getFromDatabase(string $entryIdentifier)
    {
        return parent::get($entryIdentifier);
    }

    /**
     * @param string $entryIdentifier
     * @return bool
     */
    protected function removeFromDatabase(string $entryIdentifier): bool
    {
        return parent::remove($entryIdentifier);
    }

    /**
     * Removes all cache entries of this cache.
     */
    public function flush(): void
    {
        $this->throwExceptionIfFrontendDoesNotExist();

        $baseUrl = $this->getNginxPurgeBaseUrl();
        if (empty($baseUrl)) {
            return;
        }

        $this->addEntryToPurge($baseUrl . '/*');
        $this->purge();
        if ($this->hasPurgeFailed()) {
            return;
        }

        $this->flushDatabase();
    }

    protected function flushDatabase(): void
    {
        parent::flush();
    }

    /**
     * Removes all entries tagged by any of the specified tags. Performs the SQL
     * operation as a bulk query for better performance.
     *
     * @param string[] $tags
     */
    public function flushByTags(array $tags): void
    {
        $this->throwExceptionIfFrontendDoesNotExist();

        if (empty($tags)) {
            return;
        }

        if (count($tags) > 100) {
            array_walk(array_chunk($tags, 100), [$this, 'flushByTags']);
            return;
        }

        $entries = $this->findByTags($tags);
        if (empty($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            $this->addEntryToPurge($entry['url'], $entry['identifier']);
        }
        $this->purge();
        if ($this->hasPurgeFailed()) {
            foreach ($this->getPurgedEntries() as $entry) {
                $this->removeFromDatabase($entry['identifier']);
            }
            return;
        }

        $this->flushDatabaseByTags($tags);
    }

    /**
     * @param string[] $tags
     */
    protected function flushDatabaseByTags(array $tags): void
    {
        parent::flushByTags($tags);
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     */
    public function flushByTag($tag): void
    {
        $this->throwExceptionIfFrontendDoesNotExist();

        if (empty($tag)) {
            return;
        }

        $entries = $this->findByTags([$tag]);
        if (empty($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            $this->addEntryToPurge($entry['url'], $entry['identifier']);
        }
        $this->purge();
        if ($this->hasPurgeFailed()) {
            foreach ($this->getPurgedEntries() as $entry) {
                $this->removeFromDatabase($entry['identifier']);
            }
            return;
        }

        $this->flushDatabaseByTag($tag);
    }

    /**
     * @param string $tag
     */
    protected function flushDatabaseByTag(string $tag): void
    {
        parent::flushByTag($tag);
    }

    /**
     * Finds and returns all cached urls which are tagged by the specified tag.
     *
     * @param string[] $tags The tags to search for
     * @return array An array with cache entries. An empty array if no entries matched
     */
    protected function findByTags(array $tags): array
    {
        $this->throwExceptionIfFrontendDoesNotExist();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($this->tagsTable);
        $result = $queryBuilder->select(...[
            $this->cacheTable . '.identifier',
            $this->cacheTable . '.content AS url'
        ])
            ->from($this->cacheTable)
            ->from($this->tagsTable)
            ->where(
                $queryBuilder->expr()->eq(
                    $this->cacheTable . '.identifier',
                    $queryBuilder->quoteIdentifier($this->tagsTable . '.identifier')
                ),
                $queryBuilder->expr()->in(
                    $this->tagsTable . '.tag',
                    $queryBuilder->createNamedParameter($tags, Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->gte(
                    $this->cacheTable . '.expires',
                    $queryBuilder->createNamedParameter($GLOBALS['EXEC_TIME'], \PDO::PARAM_INT)
                )
            )
            ->groupBy($this->cacheTable . '.identifier')
            ->execute();

        return $result->fetchAll(FetchMode::ASSOCIATIVE);
    }

    /**
     * Register url for purging at the Nginx FastCGI Cache
     *
     * @param string $url Url to purge
     * @param string $entryIdentifier Related local cache entry identifier
     */
    protected function addEntryToPurge(string $url, string $entryIdentifier = ''): void
    {
        $this->entriesToPurge[] = [
            'url' => $url,
            'identifier' => $entryIdentifier,
            'purged' => -1
        ];
    }

    /**
     * Purge registered urls at the Nginx FastCGI Cache
     *
     * @return void
     */
    protected function purge(): void
    {
        $this->purgeFailed = false;

        if (empty($this->entriesToPurge)) {
            return;
        }

        $client = GuzzleClientFactory::getClient();

        $requests = function () use ($client) {
            foreach ($this->entriesToPurge as $entry) {
                yield function() use ($client, $entry) {
                    return $client->requestAsync('PURGE', $entry['url']);
                };
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) {
                /** @var Response $response */
                $this->entriesToPurge[$index]['purged'] = 1;
            },
            'rejected' => function ($exception, $index) {
                /** @var \Exception $exception */
                $this->entriesToPurge[$index]['purged'] = 0;
                $this->purgeFailed = true;
                $this->logger->error(
                    sprintf('Nginx Connector: Could not purge "%s".', $this->entriesToPurge[$index]['url']),
                    ['code' => $exception->getCode(), 'message' => $exception->getMessage()]
                );
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
    }

    /**
     * Did any request fail during the last Nginx FastCGI purge?
     *
     * @return bool
     */
    protected function hasPurgeFailed(): bool
    {
        return $this->purgeFailed === true;
    }

    /**
     * Return successful purged cache entries of the last Nginx FastCGI purge
     *
     * @return array
     */
    protected function getPurgedEntries(): array
    {
        if (empty($this->entriesToPurge)) {
            return [];
        }

        return array_filter($this->entriesToPurge, function($entry) {
            return $entry['purged'] === 1;
        });
    }

    /**
     * @return string
     */
    protected function getNginxPurgeBaseUrl(): string
    {
        if ($this->baseUrl === null) {
            $this->baseUrl = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('nginx_connector', 'baseUrl');
            if (empty($this->baseUrl) && GeneralUtility::getIndpEnv('HTTP_HOST') !== NULL) {
                $this->baseUrl = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
            }
        }

        return $this->baseUrl;
    }
}
