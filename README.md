# Delete Cache Key

Delete Cache Key is a Craft CMS 4 and 5 plugin for finding and clearing cached data by cache key, wildcard, or cache tag.

## Requirements

- Craft CMS `^4.0 || ^5.0`
- PHP `>=8.0.2`

## Installation

```bash
composer require arifje/craft-delete-cache-key
php craft plugin/install delete-cache-key
```

## Control Panel Utility

The plugin adds a **Delete Cache Key** utility under Utilities.

- Search cache keys stored in Craft's `craft\cache\DbCache` or `yii\redis\Cache`.
- Clear an exact cache key.
- Clear matching cache keys with `*` or `%` wildcards.
- Search registered cache tags.
- Invalidate an exact cache tag or wildcard-matched registered cache tags.
- Search saved Cache Flag flags and invalidate exact or wildcard-matched flags.
- Clear all Cache Flag template caches when a cache was created without a specific flag.

Cache key search works against normalized cache IDs. With DB cache, those IDs come from the cache table and are restricted to the cache component's `keyPrefix`. With Redis cache, the plugin uses Redis `SCAN` and only returns keys matching the cache component's `keyPrefix` when a prefix is configured.

Exact key deletion normalizes the key through Craft. It also checks Craft/Cache Flag global template cache keys such as `template::my-key::1`, so a key like `global-categories` can clear a matching `{% cache globally using key "global-categories" %}` or `{% cacheflag globally using key "global-categories" %}` entry. Wildcard key deletion requires a discoverable backend, currently DB cache or Redis cache.

Cache tag and Cache Flag flag clearing use Yii dependency invalidation. Craft and Cache Flag accept those invalidation requests even when no matching cached value currently exists, so the utility reports them as invalidation requests rather than confirmed physical deletions.

## Register Cache Tags

In plugin settings, add one cache tag per line:

```text
custom-tag
My Custom Tag | custom-tag
```

Registered tags are added to Craft's built-in **Caches** utility under **Invalidate Data Caches**, and can also be cleared from this plugin's utility.

Use Craft's cache-tag collection in templates:

```twig
{% cache %}
  {% do craft.app.elements.collectCacheTags(['custom-tag']) %}
  ...
{% endcache %}
```

## Console Commands

Search:

```bash
php craft delete-cache-key/cache-keys/search "homepage*"
```

Clear an exact cache key:

```bash
php craft delete-cache-key/cache-keys/clear "homepage" --mode=key
```

Clear wildcard matches:

```bash
php craft delete-cache-key/cache-keys/clear "homepage*" --mode=both --wildcard=1
```

Modes are `key`, `tag`, `flag`, `cacheflag-all`, `both`, and `all`. Invalid modes are rejected before any cache operation. Console commands return a nonzero exit code on backend failure; a partial deletion can remove some entries before an error is reported.

Use `--mode=flag` to invalidate Cache Flag flags, or `--mode=all` to include keys, tags, and Cache Flag flags.

## Cache Flag Support

If [`mmikkel/cache-flag`](https://github.com/mmikkel/CacheFlag-Craft3) is installed, this plugin can invalidate flagged template caches through Cache Flag's own service:

```bash
php craft delete-cache-key/cache-keys/clear "news" --mode=flag
```

Wildcard flag clearing searches the flags saved by Cache Flag's utility. Exact flag clearing works for arbitrary flags too:

```bash
php craft delete-cache-key/cache-keys/clear "somearbitraryflag" --mode=flag
```

If a Cache Flag template cache uses `using key` without `flagged`, there is no flag-specific dependency to invalidate. For global template caches, exact key clearing will also check the generated `template::<key>::<siteId>` storage key. For cold or path-specific Cache Flag caches, clear all flagged template caches:

```bash
php craft delete-cache-key/cache-keys/clear --mode=cacheflag-all
```

## Permissions and Error Reporting

The utility and its POST actions require an administrator or the `utility:delete-cache-key` permission. Craft's normal authentication and CSRF protection apply.

Caught backend failures appear in the utility as incomplete operations rather than missing cache entries. Service results include `success` and `errors` alongside the existing result fields. Detailed exceptions remain in the application logs. Check the reported error before retrying; successful deletions in a partially failed operation are not rolled back.

## Development

Run the isolated regression checks inside an existing Craft PHP container with this checkout mounted:

```sh
docker exec <php-container> php /path/to/craft-delete-cache-key/tests/regression.php /path/to/craft/vendor
```

The checks use the installation's Craft/Yii dependencies and a disposable SQLite database in memory; they do not bootstrap the site or modify its database/cache. PHP's PDO SQLite extension is required. Run against both Craft 4 and Craft 5. Live Redis, Cache Flag, and browser integration require separate validation.

## Upgrading to 1.0.1

No schema migrations or settings changes are required. DB searches and deletions now respect the cache component's prefix; use a distinct, non-overlapping prefix for each application sharing a backend. An empty prefix still covers the entire cache table. Raw IDs from another prefix are no longer accepted as deletion targets.

Only `*` and `%` act as wildcards in DB patterns; underscores and backslashes are literal. Scripts must use a documented mode and handle nonzero exit codes for failed or incomplete operations.
