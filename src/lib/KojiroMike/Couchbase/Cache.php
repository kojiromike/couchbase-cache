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
