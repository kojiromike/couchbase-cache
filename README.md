# Couchbase Zend Cache Backend

Requires [php-couchbase](https://github.com/couchbaselabs/php-couchbase)

Quick Notes:

- `Zend_Cache_Backend_ExtendedInterface` implies tagging support. Couchbase is pretty clear that index lookups are significantly faster than queries, so to avoid having to query for tags and ids I'm using a second bucket just for tag and metadata. That's where the list of document ids exists.
- Lots of functions haven't been implemented yet.
- Where possible, I'm linking to relevant documentation within the code itself. 
