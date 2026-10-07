<?php

declare(strict_types=1);

// Run with the vendor directory of an existing Craft 4 or 5 installation.
$vendor = $argv[1] ?? dirname(__DIR__) . '/vendor';
require $vendor . '/autoload.php';
require_once $vendor . '/yiisoft/yii2/Yii.php';
require_once $vendor . '/craftcms/cms/src/Craft.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'arifje\\deletecachekey\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use arifje\deletecachekey\Plugin;
use arifje\deletecachekey\services\CacheKeys;
use yii\caching\DbCache;
use yii\db\Connection;

$app = new yii\console\Application([
    'id' => 'delete-cache-key-regression',
    'basePath' => __DIR__,
    'components' => [
        'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
        'i18n' => ['translations' => ['delete-cache-key' => [
            'class' => yii\i18n\PhpMessageSource::class,
            'sourceLanguage' => 'en-US',
        ]]],
    ],
]);
$db = $app->getDb();
$db->createCommand('CREATE TABLE cache (id TEXT PRIMARY KEY, expire INTEGER, data BLOB)')->execute();
$cache = new DbCache(['db' => $db, 'cacheTable' => 'cache', 'keyPrefix' => 'tenant_A%:', 'gcProbability' => 0]);
$app->set('cache', $cache);
$service = new CacheKeys();
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function seed(string $id): void
{
    global $db;
    $db->createCommand()->insert('cache', ['id' => $id, 'expire' => 0, 'data' => 'test'])->execute();
}
function ids(array $result): array
{
    return array_column($result['keys'], 'id');
}

// A prefix containing SQL pattern characters must remain literal.
seed('tenant_A%:homepage');
seed('tenantXAQ:homepage');
seed('tenant_B:homepage');
check(ids($service->search('*', 'key', 100)) === ['tenant_A%:homepage'], 'Search leaked another namespace');
$result = $service->clear('tenant_B:homepage', 'key');
check($result['deletedKeys'] === [], 'Exact raw ID crossed namespace');
check((int)$db->createCommand('SELECT COUNT(*) FROM cache')->queryScalar() === 3, 'Foreign ID was removed');
// Prefix contains a documented wildcard (%), so test raw exact IDs with a plain prefix too.
$cache->keyPrefix = 'local:';
seed('local:homepage');
$result = $service->clear('local:homepage', 'key');
check($result['deletedKeys'] === ['local:homepage'], 'Own raw storage ID could not be cleared');
seed('local:homepage');
check($service->clear('homepage', 'key')['deletedKeys'] === ['local:homepage'], 'Logical key was not normalized');
$cache->keyPrefix = 'tenant_A%:';
$result = $service->clear('*', 'key');
check($result['deletedKeys'] === ['tenant_A%:homepage'], 'Wildcard deletion crossed namespace');
check((int)$db->createCommand('SELECT COUNT(*) FROM cache')->queryScalar() === 2, 'Wildcard removed foreign entries');

// Only * and % are wildcards. Underscores and backslashes are literal.
$cache->keyPrefix = 'local:';
seed('local:news_item');
seed('local:newsAitem');
seed('local:path\\item');
seed('local:pathXitem');
check(ids($service->search('local:news_*', 'key', 100)) === ['local:news_item'], 'Underscore expanded during search');
check($service->clear('local:news_%', 'key')['deletedKeys'] === ['local:news_item'], 'Underscore expanded during deletion');
check($service->clear('local:path\\*', 'key')['deletedKeys'] === ['local:path\\item'], 'Backslash was not literal');
check($cache->exists('newsAitem'), 'Unintended wildcard match was deleted');

foreach (['search', 'clear'] as $operation) {
    try {
        $service->$operation('*', 'keys');
        throw new RuntimeException('Invalid mode was accepted');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'Invalid cache mode'), 'Wrong validation error');
    }
}
check($cache->exists('newsAitem'), 'Invalid mode mutated cache');

// Fail the second deletion chunk: retain partial counts and signal failure.
$db->createCommand('DELETE FROM cache')->execute();
for ($i = 0; $i <= 500; $i++) {
    seed(sprintf('local:%04d', $i));
}
$db->getMasterPdo()->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON cache WHEN old.id = 'local:0500' BEGIN SELECT RAISE(FAIL, 'simulated deletion failure'); END");
$result = $service->clear('*', 'key');
check($result['success'] === false && count($result['deletedKeys']) === 500, 'Partial deletion was not reported');
check(count($result['errors']) === 1 && $result['messages'] === [], 'Failure was hidden as a cache miss');
check(!str_contains($result['errors'][0], 'simulated deletion failure'), 'Raw backend details exposed');
check($service->search('*', 'key', 100)['success'] === true, 'Errors persisted into the next operation');

// Exercise console reporting using the real controller and a lightweight plugin instance.
$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
$plugin->setComponents(['cacheKeys' => $service]);
Plugin::setInstance($plugin);
$controller = new class('cache-keys', $app) extends arifje\deletecachekey\console\controllers\CacheKeysController {
    public string $errors = '';
    public function init(): void {}
    public function stdout($string) { return strlen($string); }
    public function stderr($string) { $this->errors .= $string; return strlen($string); }
};
$controller->mode = 'key';
check($controller->actionClear('*') !== 0, 'CLI returned success for a failed delete');
check(str_contains($controller->errors, 'Unable to delete'), 'CLI hid backend error');
$db->createCommand('DROP TABLE cache')->execute();
$result = $service->search('*', 'key', 100);
check(!$result['success'] && $result['errors'] !== [], 'Search backend failure was hidden');
check(!$service->clear('*', 'key')['success'], 'Clear discovery failure was hidden');
// Search uses plugin settings for its default limit.
$plugin->setSettings(['searchLimit' => 200]);
check($controller->actionSearch('*') !== 0, 'CLI returned success for a failed search');

echo "$checks regression checks passed. All database changes were in memory.\n";
