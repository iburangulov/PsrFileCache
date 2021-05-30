<?php

namespace iburangulov\fileCache;

use Psr\SimpleCache\CacheInterface;

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
        if (!is_readable($this->cachePath)) throw new FileCacheException('Недостаточно прав для чтения кэша');
        if (!is_writable($this->cachePath)) throw new FileCacheException('Недостаточно прав для записи кэша');

        if (file_exists($f = $this->cachePath . DIRECTORY_SEPARATOR . self::SERVICE_FILE)) {
            if (!is_readable($f)) throw new FileCacheException('Недостаточно прав для чтения служебного файла кэша');
            $this->metaData = json_decode(file_get_contents($f), true);
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

        //TODO Добавить поддержку объектного представления TTL
        if (is_object($ttl)) return false;

        if ($t = (int)$ttl) {
            $meta['ttl'] = $t;
            $meta['created'] = date('U');
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