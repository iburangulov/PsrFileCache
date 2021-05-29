<?php

namespace iburangulov\fileCache;

use Psr\SimpleCache\CacheInterface;

class CacheClient implements CacheInterface
{
    /**
     * Директория хранения кеша
     * @var string
     */
    private $cachePath;

    public function __construct(string $cachePath)
    {
        if (!is_dir($cachePath)) {
            if (!mkdir($cachePath)) throw new FileCacheException('Не удалось 
            создать директорию хранения кэша');
        }

        $this->cachePath = $cachePath;
    }

    public function get($key, $default = null)
    {
        // TODO: Implement get() method.
    }

    public function set($key, $value, $ttl = null)
    {
        // TODO: Implement set() method.
    }

    public function delete($key)
    {
        // TODO: Implement delete() method.
    }

    public function clear()
    {
        // TODO: Implement clear() method.
    }

    public function getMultiple($keys, $default = null)
    {
        // TODO: Implement getMultiple() method.
    }

    public function setMultiple($values, $ttl = null)
    {
        // TODO: Implement setMultiple() method.
    }

    public function deleteMultiple($keys)
    {
        // TODO: Implement deleteMultiple() method.
    }

    public function has($key)
    {
        // TODO: Implement has() method.
    }
}