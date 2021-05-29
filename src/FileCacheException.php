<?php

namespace iburangulov\fileCache;

use Exception;
use Psr\SimpleCache\CacheException;

class FileCacheException extends Exception implements CacheException
{

}