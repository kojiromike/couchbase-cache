<?php

/**
 * A Zend_Cache_Backend for Couchbase, intended for Magento
 * and largely inspired by Cm_Cache_Backend_Redis.
 *
 * @copyright Copyright (c) 2015 Michael A. Smith
 * @license   http://opensource.org/licenses/MIT MIT License
 * @author    Michael A. Smith
 * @see       http://developer.couchbase.com/documentation/server/4.0/sdks/php-2.0/php-intro.html
 */
class KojiroMike_Couchbase_Cache implements Zend_Cache_Backend_ExtendedInterface
{
    /** @var Couchbase */
    protected $couchbase;

    /**
     * Construct Zend_Cache Couchbase Backend
     *
     * @param array
     */
    public function __construct(array $options = [])
    {
        $this->couchbase = new Couchbase(
            $options['hosts'],
            $options['user'],
            $options['password'],
            $options['bucket'],
            $options['persistent']
        );
    }

    /**
     * Set the frontend directives
     *
     * @param array $directives assoc of directives
     */
    public function setDirectives(array $directives = [])
    {
        throw new BadMethodCallException('Not Implemented');
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * Note : return value is always "string" (unserialization is done by the core not by the backend)
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $couchbase = $this->couchbase;
        try {
            return $this->decode($couchbase->get($id));
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
     * @param  string $data            Datas to cache
     * @param  string $id              Cache id
     * @param  array $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int   $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, array $tags = [], $specificLifetime = false)
    {
        $couchbase = $this->couchbase;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id) {
        $this->couchbase->remove($id);
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
            case Zend_Cache::CLEANING_MODE_ALL:
                $this->cleanAll();
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                $this->cleanExpired();
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                $this->cleanTags($tags);
                break;
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                $this->cleanOtherTags($tags);
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                $this->cleanAllTags();
                break;
            default:
                throw new BadMethodCallException('Not Implemented');
        }
        return true;
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        throw new BadMethodCallException('Not Implemented');
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        throw new BadMethodCallException('Not Implemented');
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
        throw new BadMethodCallException('Not Implemented');
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
        throw new BadMethodCallException('Not Implemented');
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags(array $tags = [])
    {
        throw new BadMethodCallException('Not Implemented');
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        throw new BadMethodCallException('Not Implemented');
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
        throw new BadMethodCallException('Not Implemented');
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
        throw new BadMethodCallException('Not Implemented');
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
        throw new BadMethodCallException('Not Implemented');
    }

    /**
     * Decode data in the cache if it's compressed or encoded.
     *
     * @param string
     * @return string
     */
    protected function decode($data)
    {
        return $data;
    }
}
