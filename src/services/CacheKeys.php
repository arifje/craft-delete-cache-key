<?php

namespace arifje\deletecachekey\services;

use arifje\deletecachekey\models\Settings;
use arifje\deletecachekey\Plugin;
use Craft;
use craft\utilities\ClearCaches;
use Throwable;
use yii\base\Component;
use yii\caching\DbCache as YiiDbCache;
use yii\caching\TagDependency;
use yii\db\Connection;
use yii\db\Query;

class CacheKeys extends Component
{
    public const MODE_ALL = 'all';
    public const MODE_KEY = 'key';
    public const MODE_TAG = 'tag';
    public const MODE_FLAG = 'flag';
    public const MODE_CACHEFLAG_ALL = 'cacheflag-all';
    public const MODE_BOTH = 'both';

    public function search(string $pattern, string $mode = self::MODE_ALL, ?int $limit = null): array
    {
        $pattern = trim($pattern);
        $mode = $this->normalizeMode($mode);
        $limit = $limit ?? $this->searchLimit();

        $result = [
            'keys' => [],
            'tags' => [],
            'flags' => [],
            'messages' => [],
            'supportsKeySearch' => $this->supportsKeySearch(),
            'supportsCacheFlag' => $this->supportsCacheFlag(),
        ];

        if ($this->includesKeyMode($mode)) {
            if ($this->supportsKeySearch()) {
                $result['keys'] = $this->searchCacheKeys($pattern, $limit);
            } else {
                $result['messages'][] = 'Cache key search requires Craft\'s DB or Redis cache component.';
            }
        }

        if ($this->includesTagMode($mode)) {
            $result['tags'] = $this->searchRegisteredTags($pattern, $limit);
        }

        if ($this->includesFlagMode($mode)) {
            if ($this->supportsCacheFlag()) {
                $result['flags'] = $this->searchCacheFlagFlags($pattern, $limit);
            } else {
                $result['messages'][] = 'Cache Flag is not installed or is not available.';
            }
        }

        return $result;
    }

    public function clear(string $pattern, string $mode = self::MODE_ALL, bool $wildcard = false): array
    {
        $pattern = trim($pattern);
        $mode = $this->normalizeMode($mode);
        $wildcard = $wildcard || $this->hasWildcard($pattern);

        $result = [
            'deletedKeys' => [],
            'invalidatedTags' => [],
            'invalidatedFlags' => [],
            'invalidatedAllCacheFlag' => false,
            'messages' => [],
            'supportsKeySearch' => $this->supportsKeySearch(),
            'supportsCacheFlag' => $this->supportsCacheFlag(),
        ];

        if ($pattern === '' && !$this->includesCacheFlagAllMode($mode)) {
            $result['messages'][] = 'Enter a cache key, keyword, or tag first.';
            return $result;
        }

        if ($this->includesKeyMode($mode)) {
            $keyResult = $this->clearKeys($pattern, $wildcard);
            $result['deletedKeys'] = $keyResult['deletedKeys'];
            $result['messages'] = array_merge($result['messages'], $keyResult['messages']);
        }

        if ($this->includesTagMode($mode)) {
            $tagResult = $this->clearTags($pattern, $wildcard);
            $result['invalidatedTags'] = $tagResult['invalidatedTags'];
            $result['messages'] = array_merge($result['messages'], $tagResult['messages']);
        }

        if ($this->includesFlagMode($mode)) {
            $flagResult = $this->clearCacheFlagFlags($pattern, $wildcard);
            $result['invalidatedFlags'] = $flagResult['invalidatedFlags'];
            $result['messages'] = array_merge($result['messages'], $flagResult['messages']);
        }

        if ($this->includesCacheFlagAllMode($mode)) {
            $allCacheFlagResult = $this->clearAllCacheFlagCaches();
            $result['invalidatedAllCacheFlag'] = $allCacheFlagResult['invalidatedAllCacheFlag'];
            $result['messages'] = array_merge($result['messages'], $allCacheFlagResult['messages']);
        }

        return $result;
    }

    public function registeredTagOptions(): array
    {
        $settings = $this->settings();
        $lines = preg_split('/\R+/', (string)$settings->registeredTags) ?: [];
        $options = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '|')) {
                [$label, $tag] = array_map('trim', explode('|', $line, 2));
            } else {
                $tag = $line;
                $label = $line;
            }

            if ($tag === '') {
                continue;
            }

            $options[$tag] = [
                'tag' => $tag,
                'label' => $label !== '' ? $label : $tag,
            ];
        }

        return array_values($options);
    }

    public function availableTagOptions(): array
    {
        $options = [];

        foreach (ClearCaches::tagOptions() as $option) {
            if (empty($option['tag'])) {
                continue;
            }

            $tag = (string)$option['tag'];
            $options[$tag] = [
                'tag' => $tag,
                'label' => (string)($option['label'] ?? $tag),
            ];
        }

        return array_values($options);
    }

    public function supportsKeySearch(): bool
    {
        return $this->supportsDbKeySearch() || $this->supportsRedisKeySearch();
    }

    public function supportsDbKeySearch(): bool
    {
        return Craft::$app->getCache() instanceof YiiDbCache;
    }

    public function supportsRedisKeySearch(): bool
    {
        $cache = Craft::$app->getCache();

        return class_exists('yii\\redis\\Cache') && $cache instanceof \yii\redis\Cache;
    }

    public function supportsCacheFlag(): bool
    {
        return $this->cacheFlagService() !== null;
    }

    private function clearKeys(string $pattern, bool $wildcard): array
    {
        $cache = Craft::$app->getCache();
        $result = [
            'deletedKeys' => [],
            'messages' => [],
        ];

        if ($cache instanceof YiiDbCache) {
            if ($wildcard) {
                $ids = array_map(
                    static fn(array $row): string => $row['id'],
                    $this->searchDbCacheKeys($pattern, null)
                );
            } else {
                $ids = $this->storageIdsForCacheKeys($cache, $this->exactCacheKeyCandidates($pattern));
            }

            $deleted = $this->deleteDbCacheIds($cache, $ids);
            $result['deletedKeys'] = $deleted;

            if ($wildcard && empty($deleted)) {
                $result['messages'][] = 'No matching DB cache keys were found.';
            } elseif (!$wildcard && empty($deleted)) {
                $result['messages'][] = 'No exact DB cache key was found. Global template cache keys were checked as well.';
            }

            return $result;
        }

        if ($this->supportsRedisKeySearch()) {
            /** @var \yii\redis\Cache $cache */
            if ($wildcard) {
                $ids = array_map(
                    static fn(array $row): string => $row['id'],
                    $this->searchRedisCacheKeys($pattern, null, false)
                );
            } else {
                $ids = $this->storageIdsForCacheKeys($cache, $this->exactCacheKeyCandidates($pattern));
            }

            $deleted = $this->deleteRedisCacheIds($cache, $ids);
            $result['deletedKeys'] = $deleted;

            if ($wildcard && empty($deleted)) {
                $result['messages'][] = 'No matching Redis cache keys were found.';
            } elseif (!$wildcard && empty($deleted)) {
                $result['messages'][] = 'No exact Redis cache key was found. Global template cache keys were checked as well.';
            }

            return $result;
        }

        if ($wildcard) {
            $result['messages'][] = 'Wildcard cache-key deletion requires Craft\'s DB or Redis cache component.';
            return $result;
        }

        $deleted = [];

        foreach ($this->exactCacheKeyCandidates($pattern) as $key) {
            $existed = method_exists($cache, 'exists') ? $cache->exists($key) : null;
            $cache->delete($key);

            if ($existed !== false) {
                $deleted[] = $key;
            }
        }

        if (empty($deleted)) {
            $result['messages'][] = 'No cache entry was found for the exact key.';
        } else {
            $result['deletedKeys'] = array_values(array_unique($deleted));
        }

        return $result;
    }

    private function clearTags(string $pattern, bool $wildcard): array
    {
        $result = [
            'invalidatedTags' => [],
            'messages' => [],
        ];

        if ($wildcard) {
            $tags = array_map(
                static fn(array $row): string => $row['tag'],
                $this->searchRegisteredTags($pattern, null)
            );
        } else {
            $tags = [$pattern];
        }

        $tags = array_values(array_unique(array_filter($tags, static fn(string $tag): bool => $tag !== '')));

        foreach ($tags as $tag) {
            TagDependency::invalidate(Craft::$app->getCache(), $tag);
            $result['invalidatedTags'][] = $tag;
        }

        if ($wildcard && empty($tags)) {
            $result['messages'][] = 'No registered cache tags matched the wildcard.';
        } elseif (!$wildcard) {
            $result['messages'][] = 'Cache tag invalidation was requested. Craft does not expose whether an arbitrary tag existed.';
        }

        return $result;
    }

    private function clearCacheFlagFlags(string $pattern, bool $wildcard): array
    {
        $result = [
            'invalidatedFlags' => [],
            'messages' => [],
        ];

        if (!$this->supportsCacheFlag()) {
            $result['messages'][] = 'Cache Flag is not installed or is not available.';
            return $result;
        }

        $knownFlags = $this->availableCacheFlagFlags();

        if ($wildcard) {
            $flags = array_map(
                static fn(array $row): string => $row['flag'],
                $this->searchCacheFlagFlags($pattern, null)
            );
        } else {
            $flags = [$pattern];
        }

        $flags = $this->normalizeFlagList($flags);

        if (empty($flags)) {
            if ($wildcard) {
                $result['messages'][] = 'No Cache Flag flags matched the wildcard.';
            }
            return $result;
        }

        $service = $this->cacheFlagService();
        $invalidated = true;

        if ($service && method_exists($service, 'invalidateFlaggedCachesByFlags')) {
            $invalidated = (bool)$service->invalidateFlaggedCachesByFlags($flags);
        } else {
            TagDependency::invalidate(
                Craft::$app->getCache(),
                array_map(static fn(string $flag): string => "cacheflag::$flag", $flags)
            );
        }

        if ($invalidated) {
            $result['invalidatedFlags'] = $flags;
        } else {
            $result['messages'][] = 'Cache Flag did not accept the requested flag invalidation.';
        }

        if (!$wildcard) {
            if ($knownFlags !== [] && !in_array($flags[0], $knownFlags, true)) {
                $result['messages'][] = sprintf(
                    'No saved Cache Flag flag named "%s" was found. Exact arbitrary flag invalidation was requested anyway.',
                    $flags[0]
                );
            }

            $result['messages'][] = 'If your Twig uses `using key` without `flagged`, choose "All Cache Flag caches" or add a matching `flagged` value.';
        }

        return $result;
    }

    private function clearAllCacheFlagCaches(): array
    {
        $result = [
            'invalidatedAllCacheFlag' => false,
            'messages' => [],
        ];

        if (!$this->supportsCacheFlag()) {
            $result['messages'][] = 'Cache Flag is not installed or is not available.';
            return $result;
        }

        $service = $this->cacheFlagService();

        if ($service && method_exists($service, 'invalidateAllFlaggedCaches')) {
            $service->invalidateAllFlaggedCaches();
        } else {
            TagDependency::invalidate(Craft::$app->getCache(), 'cacheflag');
        }

        $result['invalidatedAllCacheFlag'] = true;
        $result['messages'][] = 'All Cache Flag template caches were invalidated via the `cacheflag` dependency tag.';

        return $result;
    }

    private function searchCacheKeys(string $pattern, ?int $limit): array
    {
        if ($this->supportsDbKeySearch()) {
            $rows = $this->searchDbCacheKeys($pattern, $limit);

            if ($pattern !== '' && !$this->hasWildcard($pattern)) {
                $rows = $this->mergeCacheKeyRows($rows, $this->searchExactDbCacheKeys($pattern));
            }

            return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
        }

        if ($this->supportsRedisKeySearch()) {
            $rows = $this->searchRedisCacheKeys($pattern, $limit);

            if ($pattern !== '' && !$this->hasWildcard($pattern)) {
                $rows = $this->mergeCacheKeyRows($rows, $this->searchExactRedisCacheKeys($pattern));
            }

            return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
        }

        return [];
    }

    private function searchDbCacheKeys(string $pattern, ?int $limit): array
    {
        $cache = Craft::$app->getCache();

        if (!$cache instanceof YiiDbCache) {
            return [];
        }

        $db = $this->dbConnection($cache);
        $query = (new Query())
            ->select(['id', 'expire'])
            ->from($cache->cacheTable)
            ->where(['or', ['expire' => 0], ['>', 'expire', time()]])
            ->orderBy(['id' => SORT_ASC]);

        if ($pattern !== '') {
            $query->andWhere(['like', 'id', $this->searchPattern($pattern), false]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        try {
            $rows = $this->withoutQueryCache($db, static fn(Connection $db): array => $query->createCommand($db)->queryAll());
        } catch (Throwable $e) {
            Craft::warning("Unable to search DB cache keys: {$e->getMessage()}", __METHOD__);
            return [];
        }

        return array_map(static function(array $row): array {
            $expire = (int)($row['expire'] ?? 0);

            return [
                'id' => (string)$row['id'],
                'expire' => $expire,
                'expires' => $expire === 0 ? 'Never' : date('Y-m-d H:i:s', $expire),
            ];
        }, $rows);
    }

    private function searchRedisCacheKeys(string $pattern, ?int $limit, bool $withMeta = true): array
    {
        $cache = Craft::$app->getCache();

        if (!class_exists('yii\\redis\\Cache') || !$cache instanceof \yii\redis\Cache) {
            return [];
        }

        $redis = $cache->redis;
        $scanPattern = $this->redisScanPattern($cache, $pattern);
        $cursor = 0;
        $rows = [];

        try {
            do {
                $response = $redis->executeCommand('SCAN', [
                    (string)$cursor,
                    'MATCH',
                    $scanPattern,
                    'COUNT',
                    '500',
                ]);

                $cursor = (int)($response[0] ?? 0);
                $keys = $response[1] ?? [];

                foreach ($keys as $key) {
                    if (!$this->isRedisCacheKey($cache, (string)$key)) {
                        continue;
                    }

                    $rows[] = $withMeta ? $this->redisKeyInfo($redis, (string)$key) : [
                        'id' => (string)$key,
                        'expire' => null,
                        'expires' => '',
                    ];

                    if ($limit !== null && count($rows) >= $limit) {
                        break 2;
                    }
                }
            } while ($cursor !== 0);
        } catch (Throwable $e) {
            Craft::warning("Unable to search Redis cache keys: {$e->getMessage()}", __METHOD__);
            return [];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $rows;
    }

    private function searchExactDbCacheKeys(string $pattern): array
    {
        $cache = Craft::$app->getCache();

        if (!$cache instanceof YiiDbCache) {
            return [];
        }

        $ids = $this->storageIdsForCacheKeys($cache, $this->exactCacheKeyCandidates($pattern));

        if (empty($ids)) {
            return [];
        }

        $db = $this->dbConnection($cache);
        $query = (new Query())
            ->select(['id', 'expire'])
            ->from($cache->cacheTable)
            ->where(['id' => $ids])
            ->andWhere(['or', ['expire' => 0], ['>', 'expire', time()]])
            ->orderBy(['id' => SORT_ASC]);

        try {
            $rows = $this->withoutQueryCache($db, static fn(Connection $db): array => $query->createCommand($db)->queryAll());
        } catch (Throwable $e) {
            Craft::warning("Unable to search exact DB cache keys: {$e->getMessage()}", __METHOD__);
            return [];
        }

        return array_map(static function(array $row): array {
            $expire = (int)($row['expire'] ?? 0);

            return [
                'id' => (string)$row['id'],
                'expire' => $expire,
                'expires' => $expire === 0 ? 'Never' : date('Y-m-d H:i:s', $expire),
            ];
        }, $rows);
    }

    private function searchExactRedisCacheKeys(string $pattern): array
    {
        $cache = Craft::$app->getCache();

        if (!class_exists('yii\\redis\\Cache') || !$cache instanceof \yii\redis\Cache) {
            return [];
        }

        $rows = [];
        $redis = $cache->redis;
        $ids = $this->storageIdsForCacheKeys($cache, $this->exactCacheKeyCandidates($pattern));

        foreach ($ids as $id) {
            if (!$this->isRedisCacheKey($cache, $id)) {
                continue;
            }

            try {
                if ((int)$redis->executeCommand('EXISTS', [$id]) > 0) {
                    $rows[] = $this->redisKeyInfo($redis, $id);
                }
            } catch (Throwable $e) {
                Craft::warning("Unable to search exact Redis cache key: {$e->getMessage()}", __METHOD__);
            }
        }

        return $this->mergeCacheKeyRows($rows);
    }

    private function mergeCacheKeyRows(array ...$rowSets): array
    {
        $indexed = [];

        foreach ($rowSets as $rows) {
            foreach ($rows as $row) {
                $indexed[(string)$row['id']] = $row;
            }
        }

        $rows = array_values($indexed);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $rows;
    }

    private function redisKeyInfo(object $redis, string $key): array
    {
        $ttl = $redis->executeCommand('TTL', [$key]);
        $ttl = is_numeric($ttl) ? (int)$ttl : -2;

        if ($ttl === -1) {
            $expires = 'Never';
        } elseif ($ttl >= 0) {
            $expires = date('Y-m-d H:i:s', time() + $ttl);
        } else {
            $expires = 'Missing';
        }

        return [
            'id' => $key,
            'expire' => $ttl,
            'expires' => $expires,
        ];
    }

    private function redisScanPattern(\yii\redis\Cache $cache, string $pattern): string
    {
        $keyPrefix = (string)$cache->keyPrefix;

        if ($pattern === '') {
            return $keyPrefix . '*';
        }

        $pattern = str_replace('%', '*', $pattern);

        if (!$this->hasWildcard($pattern)) {
            return '*' . $pattern . '*';
        }

        if ($keyPrefix !== '' && !str_starts_with($pattern, $keyPrefix) && !str_starts_with($pattern, '*')) {
            return $keyPrefix . $pattern;
        }

        return $pattern;
    }

    private function isRedisCacheKey(\yii\redis\Cache $cache, string $key): bool
    {
        $keyPrefix = (string)$cache->keyPrefix;

        return $keyPrefix === '' || str_starts_with($key, $keyPrefix);
    }

    private function exactCacheKeyCandidates(string $pattern): array
    {
        $candidates = [$pattern];

        if ($pattern !== '' && !str_starts_with($pattern, 'template::')) {
            foreach ($this->siteIds() as $siteId) {
                $candidates[] = "template::$pattern::$siteId";
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn(string $key): bool => $key !== '')));
    }

    private function storageIdsForCacheKeys(object $cache, array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $ids[] = $key;

            if (method_exists($cache, 'buildKey')) {
                $ids[] = $cache->buildKey($key);
            }
        }

        return array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
    }

    private function siteIds(): array
    {
        try {
            $sites = Craft::$app->getSites()->getAllSites();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn(object $site): int => (int)$site->id,
            $sites
        )));
    }

    private function searchRegisteredTags(string $pattern, ?int $limit): array
    {
        $matches = [];

        foreach ($this->availableTagOptions() as $option) {
            $tag = (string)$option['tag'];
            $label = (string)$option['label'];

            if ($pattern !== '' && !$this->matchesPattern($tag, $pattern) && !$this->matchesPattern($label, $pattern)) {
                continue;
            }

            $matches[] = [
                'tag' => $tag,
                'label' => $label,
            ];

            if ($limit !== null && count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    private function searchCacheFlagFlags(string $pattern, ?int $limit): array
    {
        $matches = [];

        foreach ($this->availableCacheFlagFlags() as $flag) {
            if ($pattern !== '' && !$this->matchesPattern($flag, $pattern)) {
                continue;
            }

            $matches[] = ['flag' => $flag];

            if ($limit !== null && count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    private function availableCacheFlagFlags(): array
    {
        $service = $this->cacheFlagService();

        if (!$service || !method_exists($service, 'getAllFlags')) {
            return [];
        }

        try {
            $rows = $service->getAllFlags();
        } catch (Throwable $e) {
            Craft::warning("Unable to read Cache Flag flags: {$e->getMessage()}", __METHOD__);
            return [];
        }

        $flags = [];

        foreach ($rows as $row) {
            $flags = array_merge($flags, $this->normalizeFlagList([(string)($row['flags'] ?? '')]));
        }

        sort($flags);

        return array_values(array_unique($flags));
    }

    private function deleteDbCacheIds(YiiDbCache $cache, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));

        if (empty($ids)) {
            return [];
        }

        $db = $this->dbConnection($cache);
        $deleted = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            try {
                $existing = (new Query())
                    ->select(['id'])
                    ->from($cache->cacheTable)
                    ->where(['id' => $chunk])
                    ->column($db);

                if (empty($existing)) {
                    continue;
                }

                $db->createCommand()
                    ->delete($cache->cacheTable, ['id' => $existing])
                    ->execute();

                $deleted = array_merge($deleted, $existing);
            } catch (Throwable $e) {
                Craft::warning("Unable to delete DB cache keys: {$e->getMessage()}", __METHOD__);
            }
        }

        return array_values(array_unique($deleted));
    }

    private function deleteRedisCacheIds(\yii\redis\Cache $cache, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));

        if (empty($ids)) {
            return [];
        }

        $deleted = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            try {
                $existing = [];

                foreach ($chunk as $id) {
                    if (!$this->isRedisCacheKey($cache, $id)) {
                        continue;
                    }

                    if ((int)$cache->redis->executeCommand('EXISTS', [$id]) > 0) {
                        $existing[] = $id;
                    }
                }

                if (empty($existing)) {
                    continue;
                }

                $cache->redis->executeCommand('DEL', $existing);
                $deleted = array_merge($deleted, $existing);
            } catch (Throwable $e) {
                Craft::warning("Unable to delete Redis cache keys: {$e->getMessage()}", __METHOD__);
            }
        }

        return array_values(array_unique($deleted));
    }

    private function dbConnection(YiiDbCache $cache): Connection
    {
        /** @var Connection */
        return $cache->db;
    }

    private function withoutQueryCache(Connection $db, callable $callback): array
    {
        if (method_exists($db, 'noCache')) {
            return $db->noCache($callback);
        }

        $enableQueryCache = $db->enableQueryCache;
        $db->enableQueryCache = false;

        try {
            return $callback($db);
        } finally {
            $db->enableQueryCache = $enableQueryCache;
        }
    }

    private function searchPattern(string $pattern): string
    {
        return $this->hasWildcard($pattern) ? str_replace('*', '%', $pattern) : '%' . $pattern . '%';
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        if (!$this->hasWildcard($pattern)) {
            return stripos($value, $pattern) !== false;
        }

        $quoted = preg_quote(str_replace('%', '*', $pattern), '/');
        $regex = '/^' . str_replace('\*', '.*', $quoted) . '$/i';

        return preg_match($regex, $value) === 1;
    }

    private function hasWildcard(string $pattern): bool
    {
        return str_contains($pattern, '*') || str_contains($pattern, '%');
    }

    private function normalizeMode(string $mode): string
    {
        return in_array($mode, [self::MODE_ALL, self::MODE_KEY, self::MODE_TAG, self::MODE_FLAG, self::MODE_CACHEFLAG_ALL, self::MODE_BOTH], true)
            ? $mode
            : self::MODE_ALL;
    }

    private function includesKeyMode(string $mode): bool
    {
        return in_array($mode, [self::MODE_ALL, self::MODE_KEY, self::MODE_BOTH], true);
    }

    private function includesTagMode(string $mode): bool
    {
        return in_array($mode, [self::MODE_ALL, self::MODE_TAG, self::MODE_BOTH], true);
    }

    private function includesFlagMode(string $mode): bool
    {
        return in_array($mode, [self::MODE_ALL, self::MODE_FLAG], true);
    }

    private function includesCacheFlagAllMode(string $mode): bool
    {
        return $mode === self::MODE_CACHEFLAG_ALL;
    }

    private function normalizeFlagList(array $flags): array
    {
        $normalized = [];

        foreach ($flags as $flag) {
            foreach (preg_split('/[,|]+/', preg_replace('/\s+/', '', $flag) ?: '') ?: [] as $part) {
                if ($part !== '') {
                    $normalized[] = $part;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    private function cacheFlagService(): ?object
    {
        if (!class_exists('mmikkel\\cacheflag\\CacheFlag')) {
            return null;
        }

        try {
            $class = 'mmikkel\\cacheflag\\CacheFlag';
            $plugin = $class::getInstance();

            if (!$plugin || !method_exists($plugin, 'get')) {
                return null;
            }

            return $plugin->get('cacheFlag', false);
        } catch (Throwable) {
            return null;
        }
    }

    private function searchLimit(): int
    {
        return max(1, min(1000, $this->settings()->searchLimit));
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return Plugin::getInstance()->getSettings();
    }
}
