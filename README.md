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

Cache key search works against normalized cache IDs. With DB cache, those IDs come from the cache table. With Redis cache, the plugin uses Redis `SCAN` and only returns keys matching the cache component's `keyPrefix` when a prefix is configured.

Exact key deletion still normalizes the key through Craft. Wildcard key deletion requires a discoverable backend, currently DB cache or Redis cache.

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

Modes are `key`, `tag`, and `both`.

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
