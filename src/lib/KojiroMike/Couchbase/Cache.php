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

    const DOCUMENT_ID_DELIMITER = ' ';

    /** @var CouchbaseBucket */
    protected $couchbase;
    /** @var CouchbaseBucket */
    protected $tagBucket;

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

    /**
     * @var array
     *
     * Default options
     */
    protected $defaultOptions = [
        // An optional callable that takes a dsn, username and password and returns a CouchbaseCluster
        'cluster_factory' => null,
        // The dsn string to connect to the CouchbaseCluster
        'dsn' => 'http://127.0.0.1/',
        // The username to connect to the CouchbaseCluster
        'username' => '',
        // The password to connect to the CouchbaseCluster
        'password' => '',
        // The name of the CouchbaseBucket to open
        'bucket_name' => 'default',
        // The password to the CouchbaseBucket
        'bucket_password' => '',
        // The name of the bucket to store the tag index in
        'tag_bucket' => 'tags',
        // The password to the tag index bucket
        'tag_bucket_password' => '',
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
        $couchbaseCluster = $this->getCluster($options);
        $this->couchbase = $couchbaseCluster->openBucket($options['bucket'], $options['bucket_password']);
        $this->tagBucket = $couchbaseCluster->openBucket($options['tag_bucket'], $options['tag_bucket_password']);
    }

    /**
     * Set the frontend directives
     *
     * @param array $directives assoc of directives
     */
    public function setDirectives(array $directives = [])
    {
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
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
        $couchbase = $this->couchbase;
        try {
            return $couchbase->get($id);
        } catch (CouchbaseNoSuchKeyException $e) {
            return false;
        }
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
        // can't figure out how to get the actual expiration date from Couchbase.
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
    public function save($data, $id, array $tags = [], $specificLifetime = false)
    {
        $couchbase = $this->couchbase;
        $options = [];
        if ($specificLifetime) {
            $options['expiry'] = $this->convertLifetimeToExpiry($specificLifetime);
        }
        $options['flags'] = self::COUCHBASE_FORMAT_FLAGS_RAW;
        $couchbase->upsert($id, $data, $options);
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
        $this->couchbase->remove($id);
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
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        switch($mode) {
            case Zend_Cache::CLEANING_MODE_ALL: return $this->cleanAll();
            case Zend_Cache::CLEANING_MODE_OLD: return $this->cleanExpired();
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG: return $this->cleanTags($tags);
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG: return $this->cleanOtherTags($tags);
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG: return $this->cleanAllTags();
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
    public function getIdsMatchingTags(array $tags = [])
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
    public function getIdsNotMatchingTags(array $tags = [])
    {
        $bucket = $this->tagBucket;
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
    public function getIdsMatchingAnyTags(array $tags = [])
    {
        $bucket = $this->tagBucket;
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
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
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
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
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
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
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
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
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
        throw new BadMethodCallException('Not Implemented: ' . __METHOD__);
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
        $bucket = $this->tagBucket;
        // Store the tag itself in the document for listing used tags.
        $bucket->append('_tags', implode(self::DOCUMENT_ID_DELIMITER, $tags) . self::DOCUMENT_ID_DELIMITER);
        $tags[] = '_all'; // Always add the id to the _all tag.
        $bucket->append($tags, $id . self::DOCUMENT_ID_DELIMITER);
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
}
