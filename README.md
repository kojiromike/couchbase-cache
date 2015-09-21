# Couchbase Zend Cache Backend

My primary goal is a fast distributed caching backend for applications like [Magento](http://magento.com/). Writes should be fast, reads faster. I'd like to take advantage of Couchbase features like [document expiration](http://docs.couchbase.com/developer/dev-guide-3.0/doc-expiration.html) when possible, rather than implementing them in PHP.

I'm aware of [PSR-6](https://github.com/php-fig/fig-standards/blob/master/proposed/cache.md) and tracking it, but don't want to distract from the primary goal (at least until PSR-6 is accepted).

Requires [php-couchbase](https://github.com/couchbaselabs/php-couchbase). See tools/Dockerfile for one way to build that php extension.

Quick Notes:

- `Zend_Cache_Backend_ExtendedInterface` implies tagging support. Couchbase is pretty clear that index lookups are significantly faster than queries, so to avoid having to query for tags and ids I'm using a second bucket just for tag and metadata. That's where the list of document ids exists.
- Most functions have been implemented now. Biggest remaining question is around expiry. See the issues.
- Where possible, I'm linking to relevant documentation within the code itself.

Please see the [issues section in GitHub](https://github.com/kojiromike/couchbase-cache/issues) for the list of open issues.
