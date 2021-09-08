<?php

namespace iburangulov\fileCache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

use function rtrim;
use function is_dir;
use function mkdir;
use function is_readable;
use function is_writable;
use function file_exists;
use function json_encode;
use function json_decode;
use function file_get_contents;
use function file_put_contents;
use function disk_free_space;
use function scandir;
use function array_merge;
use function array_keys;
use function array_diff;
use function sha1;
use function array_key_exists;
use function serialize;
use function unserialize;
use function settype;
use function gettype;
use function in_array;
use function date;
use function is_int;
use function is_object;
use function abs;
use function unlink;

final class CacheClient implements CacheInterface
{
    /*
     * Файл со служебной информацией
     */
    const SERVICE_FILE = '.metadoc';

    /**
     * Директория хранения кэша
     * @var string
     */
    private $cachePath = __DIR__ . '/cache';

    /**
     * Мета - данные о кэше
     * @var array
     */
    private $metaData = [];

    /**
     * Временные данные
     * @var array
     */
    private $temp;

    /**
     * Ранее загруженные данные
     * @var array
     */
    private $loaded = [];

    /**
     * CacheClient constructor.
     * @param string|null $cachePath Директория хранения кэша
     * @throws FileCacheException
     */
    public function __construct(string $cachePath = null)
    {
        if ($cachePath) $this->cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);

        if (!is_dir($this->cachePath)) {
            if (@!mkdir($this->cachePath)) throw new FileCacheException('Не удалось создать директорию хранения кэша');
        }
        if (!is_readable($this->cachePath) || !is_writable($this->cachePath)) throw new FileCacheException('Недостаточно прав');

        if (file_exists($f = $this->cachePath . DIRECTORY_SEPARATOR . self::SERVICE_FILE)) {
            if (!is_readable($f)) throw new FileCacheException('Недостаточно прав для чтения служебного файла кэша');
            $this->metaData = json_decode(file_get_contents($f), true) ?? [];
        }

        if (disk_free_space($this->cachePath) <= 8) throw new FileCacheException('Недостаточно места на диске для хранения кэша');

        $this->temp['set'] = [];
        $this->temp['delete'] = [];

        $cacheFiles = scandir($this->cachePath);
        $protected = ['.', '..', self::SERVICE_FILE];
        $cacheKeys = array_merge(array_keys($this->metaData), $protected);

        if ($diff = array_diff($cacheKeys, $cacheFiles)) {
            foreach ($diff as $item) {
                unset($this->metaData[$item]);
            }
        }
    }

    /**
     * Получить данные из кеша по ключу
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if (!$this->has($key)) return $default;

        $k = sha1($key);

        if (array_key_exists($k, $this->temp['set'])) return $this->temp['set'][$k];
        if (array_key_exists($k, $this->loaded)) return $this->loaded[$k];

        if (file_exists($this->getCacheFile($key))) {
            $data = file_get_contents($this->getCacheFile($key));
            if (!array_key_exists($k, $this->metaData)) $this->metaData[$k] = ['type' => 'string'];
            if ($this->isKeyExpired($key)) return $default;

            if (array_key_exists('serialized', $this->metaData[$k])
                && $this->metaData[$k]['serialized']) {
                $data = unserialize($data);
            }

            if (array_key_exists('type', $this->metaData[$k])) {
                settype($data, $this->metaData[$k]['type']);
            }

            $this->loaded[$k] = $data;
            return $data;
        }
        return $default;
    }

    /**
     * Записать данные в кэш
     * @param string $key
     * @param mixed $value
     * @param null $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = null): bool
    {
        $k = sha1($key);
        if (in_array($k, $this->temp['delete'])) unset($this->temp['delete'][$k]);

        $meta['type'] = gettype($value);

        if (is_array($value)) {
            $value = serialize($value);
            $meta['serialized'] = true;
        }

        if ($ttl)
        {
            $meta['created'] = date('U');

            if (is_int($ttl)) {
                $meta['ttl'] = $ttl;
            } elseif (is_object($ttl) && $ttl instanceof DateInterval) {
                $daysInSeconds = abs($ttl->days) * 86400;
                $hoursInSeconds = abs($ttl->format('%h')) * 3600;
                $minutesInSeconds = abs($ttl->format('%i')) * 60;
                $seconds = abs($ttl->format('%s'));
                $meta['ttl'] = $daysInSeconds + $hoursInSeconds + $minutesInSeconds + $seconds;
            } else {
                throw new CacheInvalidArgumentException('Тип $ttl должен быть либо int либо реализацией класса \DateInterval');
            }
        }

        $this->metaData[$k] = $meta;
        $this->temp['set'][$k] = $value;

        if (array_key_exists($k, $this->loaded)) unset($this->loaded[$k]);

        return true;
    }

    /**
     * Удалить данные из кэша по ключу
     * @param string $key
     * @return bool
     */
    public function delete($key): bool
    {
        $k = sha1($key);

        if (!$this->has($key) || in_array($k, $this->temp['delete'])) return false;

        if (array_key_exists($k, $this->metaData)) unset($this->metaData[$k]);
        if (array_key_exists($k, $this->temp['set'])) unset($this->temp['set'][$k]);
        if (array_key_exists($k, $this->loaded)) unset($this->loaded[$k]);
        $this->temp['delete'][] = $k;
        return true;
    }

    /**
     * Удалить все данные из кэша
     * @return void
     */
    public function clear(): void
    {
        $this->metaData = [];
    }

    /**
     * Получить массив значений по массиву ключей
     * @param iterable $keys
     * @param null $default
     * @return iterable|void
     */
    public function getMultiple($keys, $default = null)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        if (!$result) return $default;
        return $result;
    }

    /**
     * Записать данные в кэш по ключ => значние
     * @param iterable $values
     * @param null $ttl
     * @return void
     */
    public function setMultiple($values, $ttl = null): void
    {
        foreach ($values as $key => $value)
        {
            $this->set($key, $value, $ttl);
        }
    }

    /**
     * Удалить массив ключей
     * @param iterable $keys
     * @return void
     */
    public function deleteMultiple($keys): void
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
    }

    /**
     * Проверить наличие ключа в кэше
     * @param string $key
     * @return bool
     */
    public function has($key): bool
    {
        $k = sha1($key);
        if (array_key_exists($k, $this->metaData) && !$this->isKeyExpired($key)) return true;
        return false;
    }

    /**
     * Получить путь до кеш - файла на диске
     * @param $key
     * @return string
     */
    private function getCacheFile(string $key, $alreadyCrypdedKey = false): string
    {
        $k = $alreadyCrypdedKey ? $key : sha1($key);
        return $this->cachePath . DIRECTORY_SEPARATOR . $k;
    }

    /**
     * Завершение работы кэша
     */
    private function shutdown(): void
    {
        //Удаление просроченных ключей
        foreach ($this->metaData as $k => $meta) {
            if ($this->isKeyExpired($k, true)) {
                $this->temp['delete'][] = $k;
                unset($this->metaData[$k]);
                if (array_key_exists($k, $this->loaded)) unset($this->loaded[$k]);
                if (array_key_exists($k, $this->temp['set'])) unset($this->temp['set'][$k]);
            }
        }

        //Сохранение мета - данных
        file_put_contents($this->cachePath . DIRECTORY_SEPARATOR . self::SERVICE_FILE,
            $this->metaData ? json_encode($this->metaData) : '');

        //Сохранение данных
        if ($this->temp['set']) {
            foreach ($this->temp['set'] as $k => $v) {
                file_put_contents($this->getCacheFile($k, true), $v);
            }
        }

        //Удаление данных
        if ($delete = $this->temp['delete']) {
            foreach ($delete as $k => $item) {
                if (file_exists($f = $this->getCacheFile($k, true))) unlink($f);
            }
        }

        $cacheFiles = scandir($this->cachePath);
        foreach ($cacheFiles as $cacheFile) {
            $protected = ['.', '..', self::SERVICE_FILE];
            if (!array_key_exists($cacheFile, $this->metaData) && !in_array($cacheFile, $protected)) {
                unlink($this->getCacheFile($cacheFile, true));
            }
        }

    }

    /**
     * Проверка просроченности ключа по TTL
     * @param string $key
     * @param false $isCryptedKey
     * @return bool true - просрочен, false - не просрочен
     */
    private function isKeyExpired(string $key, $isCryptedKey = false): bool
    {
        $k = $isCryptedKey ? $key : sha1($key);

        if (array_key_exists($k, $this->metaData)) {
            if (array_key_exists('ttl', $this->metaData[$k]) &&
                array_key_exists('created', $this->metaData[$k])
            ) {
                $now = date('U');
                $created = $this->metaData[$k]['created'];
                $ttl = $this->metaData[$k]['ttl'];

                if (($created + $ttl) < $now) return true;
            }
            return false;
        }
        return true;
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
