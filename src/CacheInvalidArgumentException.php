<?php

namespace iburangulov\fileCache;

use Psr\SimpleCache\InvalidArgumentException;

class CacheInvalidArgumentException extends FileCacheException implements InvalidArgumentException
{

}