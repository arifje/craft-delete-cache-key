# Changelog

## 1.0.1 - 2026-10-07

- Restricted DB cache searches and deletions to the configured cache key prefix, including raw storage IDs.
- Escaped literal underscores and backslashes in DB wildcard patterns.
- Reported caught cache backend errors and partial failures in the utility and returned nonzero console exit codes.
- Rejected invalid cache modes before any cache operation.
- Added isolated regression checks runnable against Craft 4 and Craft 5 dependencies.

## 1.0.0 - 2026-06-17

- Initial Craft 4 and Craft 5 compatible release.
- Added a Utilities CP tool for searching and clearing cache keys or tags.
- Added plugin settings for registering custom cache tags with Craft's Caches utility.
- Added console commands for searching and clearing keys/tags.
- Added Redis cache key search and deletion support.
- Added Cache Flag flag search and invalidation support.
- Added exact global template cache key deletion for Craft/Cache Flag `using key` caches.
- Added an all-CacheFlag invalidation mode for cold or path-specific Cache Flag template caches.
- Clarified tag and Cache Flag flag results as invalidation requests rather than confirmed physical deletions.
