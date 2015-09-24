<?php

/**
 * A Zend_Cache_Backend for Couchbase, intended for Magento
 * and largely inspired by Cm_Cache_Backend_Redis.
 *
 * @copyright Copyright (c) 2015 Michael A. Smith
 * @license   http://opensource.org/licenses/MIT MIT License
 * @author    Michael A. Smith
 * @see       http://developer.couchbase.com/documentation/server/4.0/sdks/php-2.0/php-intro.html
 * @see       http://docs.couchbase.com/sdk-api/couchbase-php-client-2.0.7
 */
class KojiroMike_Couchbase_Cache implements Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Format flag constants
     * I can't find how these are actually set in the library.
     *
     * @see http://developer.couchbase.com/documentation/server/4.0/developer-guide/transcoders.html#concept_bdb_smb_bt__table_wrc_2nb_bt
     */
    const COUCHBASE_FORMAT_FLAGS_JSON = 33554432; // 0x02 << 24
    const COUCHBASE_FORMAT_FLAGS_UTF8 = 67108864; // 0x04 << 24
    const COUCHBASE_FORMAT_FLAGS_RAW = 50331648; // 0x03 << 24
    const COUCHBASE_FORMAT_FLAGS_PRIVATE = 16777216; // 0x01 << 24

    /** @var CouchbaseBucket */
    protected $bucket;

    /** @var int */
    protected $lifetime;
    /** @var bool */
    protected $logging;
    /** @var Zend_Log|null */
    protected $logger;

    /**
     * @var array
     * @see Zend_Cache_Backend_ExtendedInterface::getCapabilities
     *
     * This is the todo list!
     */
    protected $capabilities = [
        'automatic_cleaning' => false,
        'tags' => false,
        // @see http://developer.couchbase.com/documentation/server/4.0/developer-guide/expiry.html
        'expired_read' => false,
        'priority' => false,
        'infinite_lifetime' => false,
        'get_list' => false,
    ];

    protected $defaultDirectives = [
        'lifetime' => 900,
        'logger' => null,
        'logging' => false,
    ];

    /**
     * @var array
     *
     * Default options
     */
    protected $defaultOptions = [
        // An optional callable that takes a dsn, username and password and returns a CouchbaseCluster
        'cluster_factory' => null,
        // The dsn string to connect to the CouchbaseCluster
        'dsn' => 'couchbase://127.0.0.1/',
        // The username to connect to the CouchbaseCluster
        'username' => '',
        // The password to connect to the CouchbaseCluster
        'password' => '',
        // The name of the CouchbaseBucket to open
        'bucket_name' => 'default',
        // The password to the CouchbaseBucket
        'bucket_password' => '',
    ];

    /**
     * Construct Zend_Cache Couchbase Backend
     *
     * @param array $options
     * @see self::defaultOptions
     */
    public function __construct(array $options = [])
    {
        $options = array_merge($this->defaultOptions, $options);
        $bucket = $this->getBucket($options);
        $this->bucket = $bucket;
    }

    /**
     * Set the frontend directives
     *
     * @param array $directives assoc of directives
     */
    public function setDirectives($directives = [])
    {
        $directives = array_merge($this->defaultDirectives, (array) $directives);
        $this->lifetime = (int) $directives['lifetime'];
        $this->logging = (bool) $directives['logging'];
        $this->setLogger($directives['logger']);
    }

    protected function setLogger(Zend_Log $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * Note : return value is always "string" (unserialization is done by the core not by the backend)
     *
     * @see http://docs.couchbase.com/sdk-api/couchbase-php-client-2.0.7/classes/CouchbaseBucket.html#method_get
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $bucket = $this->bucket;
        try {
            return $bucket->get($id);
        } catch (CouchbaseException $e) {
            if ($e->getMessage() !== 'The key does not exist on the server') {
                throw $e;
            }
        }
        return false;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        // Not clear how to get the last modified timestamp from couchbase
        // Also not clear what Zend expects if the document exists, but last modified time does not...
        try {
            $result = $this->bucket->get($id);
            if ($result) {
                // So for now, if the document exists, just return an integer. ¯\_(ツ)_/¯
                return 1;
            }
        } catch (CouchbaseException $e) {
            if ($e->getMessage() !== 'The key does not exist on the server') {
                throw $e;
            }
        }
        return false;
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @see http://docs.couchbase.com/sdk-api/couchbase-php-client-2.0.7/classes/CouchbaseBucket.html#method_upsert
     * @param  string $data            Datas to cache
     * @param  string $id              Cache id
     * @param  array $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int   $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, $tags = [], $specificLifetime = false)
    {
        $bucket = $this->bucket;
        $options = [];
        if ($specificLifetime) {
            $options['expiry'] = $this->getLifetime($specificLifetime);
        }
        // $options['flags'] = self::COUCHBASE_FORMAT_FLAGS_RAW;
        $bucket->upsert($id, $data, $options);
        $this->tagId($id, $tags);
        return true;
    }

    /**
     * Remove a cache record
     *
     * @see http://docs.couchbase.com/sdk-api/couchbase-php-client-2.0.7/classes/CouchbaseBucket.html#method_remove
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id) {
        // CouchbaseBucket::remove actually takes an array of ids or a single id.
        $bucket = $this->bucket;
        try {
            $bucket->remove($id);
        } catch (CouchbaseException $e) {
            if ($e->getMessage() !== 'The key does not exist on the server') {
                throw $e;
            }
            return false;
        }
        return true;
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean true if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = [])
    {
        $tags = (array) $tags;
        switch($mode) {
            case Zend_Cache::CLEANING_MODE_ALL: return $this->cleanAll();
            case Zend_Cache::CLEANING_MODE_OLD: return $this->cleanExpired();
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG: return $this->cleanAllTags($tags);
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG: return $this->cleanOtherTags($tags);
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG: return $this->cleanAnyTags();
        }
        throw new BadMethodCallException('Unexpected parameters to ' . __METHOD__);
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        return $this->getIdsMatchingAnyTags(['_all']);
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return $this->getIdsMatchingAnyTags(['_tags']);
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = [])
    {
        $ids = array_map([$this, 'getIdsMatchingAnyTags'], array_chunk($tags, 1));
        return call_user_func_array('array_intersect', $ids);
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = [])
    {
        $bucket = $this->bucket;
        return array_diff($this->getIds(), $this->getIdsMatchingAnyTags($tags));
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = [])
    {
        $bucket = $this->bucket;
        $allIdsString = $bucket->get($tags);
        // Note: Given many reads, few writes
        // (When the time comes to deal with uniqueness)
        // Then uniquify the ids in self::tagId
        // And not here
        $allIds = explode(self::DOCUMENT_ID_DELIMITER, $allIdsString);
        return $allIds;
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $this->bucket->touch($id, $this->getLifetime($extraLifetime));
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return $this->capabilities;
    }

    /**
     * Remove all cache entries
     * Zend_Cache::CLEANING_MODE_ALL
     *
     * @return bool true if no problem
     */
    protected function cleanAll()
    {
        $this->bucket->manager()->flush();
        return true;
    }

    /**
     * Remove too old cache entries
     * Zend_Cache::CLEANING_MODE_OLD
     *
     * @return boolean true if no problem
     */
    protected function cleanExpired()
    {
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
    }

    /**
     * Remove cache entries matching all given tags
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG
     *
     * @param  array  $tags Array of tags
     * @return boolean true if no problem
     */
    protected function cleanAllTags(array $tags = [])
    {
        $ids = $this->getIdsMatchingTags($tags);
        $this->bucket->remove($ids);
        return true;
    }

    /**
     * Remove cache entries not matching one of the given tags
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG
     *
     * @param  array  $tags Array of tags
     * @return boolean true if no problem
     */
    protected function cleanNotTags(array $tags = [])
    {
        $ids = $this->getIdsNotMatchingTags($tags);
        $this->bucket->remove($ids);
        return true;
    }

    /**
     * Remove cache entries matching any given tags
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG
     *
     * @param  array  $tags Array of tags
     * @return boolean true if no problem
     */
    protected function cleanAnyTags(array $tags = [])
    {
        $ids = $this->getIdsMatchingAnyTags($tags);
        $this->bucket->remove($ids);
        return true;
    }

    /**
     * Associate a document with some tags.
     *
     * @see http://docs.couchbase.com/sdk-api/couchbase-php-client-2.0.7/classes/CouchbaseBucket.html#method_append
     * @param string $id
     * @param array $tags
     * @return self
     */
    protected function tagId($id, array $tags)
    {
        $doc = $this->load('_tags') ?: '{}';
        $data = json_decode($tagDoc, true);
        foreach($tags as $tag) {
            $data[$tag][$id] = 1;
        }
        $bucket = $this->bucket;
        $bucket->upsert('_tags', json_encode($data));
        return $this;
    }


    /**
     * Enable injecting the CouchbaseCluster implementation.
     *
     * @param array $options
     * @see self::__construct
     * @return CouchbaseCluster
     */
    protected function getCluster(array $options = [])
    {
        $clusterFactory = is_callable($options['cluster_factory']) ? $options['cluster_factory'] :
            function($dsn, $user, $pass) {
                return new CouchbaseCluster($dsn, $user, $pass);
            };
        return $clusterFactory($options['dsn'], $options['username'], $options['password']);
    }

    /**
     * Enable creating the buckets on demand
     *
     * @param array $options
     * @return CouchbaseBucket
     */
    protected function getBucket(array $options = [])
    {
        $cluster = $this->getCluster($options);
        $bucket = $options['bucket'];
        $password = $options['bucket_password'];
        try {
            return $cluster->openBucket($bucket, $password);
        } catch (CouchbaseException $e) {
            if ($e->getMessage() !== 'The bucket requested does not exist') {
                throw $e;
            }
        }
        $manager = $cluster->manager($options['username'], $options['password']);
        return $manager->createBucket($bucket);
    }

    /**
     * Allow falling back to Zend_Cache_Backend global lifetime.
     * However, if the lifetime is > 30 x 24 x 60 x 60 (the number
     * of seconds in a month, couchbase expects a UNIX epoch timestamp.
     * As long as you haven't time traveled to before February 1970,
     * you might as well always use an epoch timestamp.
     *
     * @param int|bool TTL of the document, or false
     * @return int the epoch timestamp + the TTL
     */
    public function getLifetime($specificLifetime = false)
    {
        $lifetime = time() + ($specificLifetime === false ? $this->_directives['lifetime'] : $specificLifetime);
        return $lifetime;
    }

}
