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
     * Директория хранения кеша
     * @var string
     */
    private $cachePath;

    /**
     * Служебная информация о кэше
     * @var array
     */
    private $meta = [];

    /**
     * CacheClient constructor.
     * @param string $cachePath
     * @throws FileCacheException
     */
    public function __construct(string $cachePath = __DIR__ . DIRECTORY_SEPARATOR . 'cache')
    {
        $cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);
        if (!is_dir($cachePath)) {
            if (@!mkdir($cachePath)) throw new FileCacheException('Не удалось создать директорию хранения кэша');
        }
        if (!is_readable($cachePath)) throw new FileCacheException('Недостаточно прав для чтения кэша');
        if (!is_writable($cachePath)) throw new FileCacheException('Недостаточно прав для записи кэша');

        if (file_exists($f = $cachePath . DIRECTORY_SEPARATOR . self::SERVICE_FILE)) {
            if (!is_readable($f)) throw new FileCacheException('Недостаточно прав для чтения служебного файла кэша');
            $this->meta = json_decode(file_get_contents($f), true);
        }

        if (disk_free_space($cachePath) <= 8) throw new FileCacheException('Недостаточно места на диске для хранения кэша');

        $this->cachePath = $cachePath;
    }

    /**
     * Получить значение по ключу
     * @param string $key
     * @param null $default
     * @return mixed|void
     */
    public function get($key, $default = null)
    {
        if (!$this->has($key)) return false;

        $metaInformation = $this->getMetaData($key);
        $data = file_get_contents($this->cachePath . DIRECTORY_SEPARATOR . sha1($key));

        if (!$data) return $default;

        if ($metaInformation)
        {
            if (isset($metaInformation['serialized']) && $metaInformation['serialized'] === true) $data = unserialize($data);
            if (isset($metaInformation['ttl']) && isset($metaInformation['created']))
            {

            }
        }

        var_dump($data);
    }

    /**
     * Установить значение для ключа
     * @param string $key
     * @param mixed $value
     * @param null $ttl
     * @return bool|void
     */
    public function set($key, $value, $ttl = null)
    {
        //TODO Добавить поддержку DateInterval Object для TTL
        $metaInformation = [];
        $k = sha1($key);

        if (is_array($value))
        {
            $value = serialize($value);
            $metaInformation['serialized'] = true;
        }

        if ($ttl && is_int($ttl))
        {
            $metaInformation['ttl'] = $ttl;
            $metaInformation['created'] = date('U');
        }

        if ($metaInformation) $this->meta[$k] = $metaInformation;

        file_put_contents($this->cachePath . DIRECTORY_SEPARATOR . $k, $value);
    }

    /**
     * Удалить ключ и его значение
     * @param string $key
     * @return bool
     */
    public function delete($key): bool
    {
        if ($this->has($key))
        {
            $k = sha1($key);
            if (isset($this->meta[$k])) unset($this->meta[$k]);
            return unlink($this->cachePath . DIRECTORY_SEPARATOR . $k);
        }
        return false;
    }

    /**
     * Очисть весь кэш
     * @return bool|void
     */
    public function clear()
    {
        // TODO: Implement clear() method.
    }

    /**
     * Получить несколько значений по ключам
     * @param iterable $keys
     * @param null $default
     * @return iterable|void
     */
    public function getMultiple($keys, $default = null)
    {
        // TODO: Implement getMultiple() method.
    }

    /**
     * Установить несколько значнений по ключам
     * @param iterable $values
     * @param null $ttl
     * @return bool|void
     */
    public function setMultiple($values, $ttl = null)
    {
        // TODO: Implement setMultiple() method.
    }

    /**
     * Удалить несколько ключей
     * @param iterable $keys
     * @return bool|void
     */
    public function deleteMultiple($keys)
    {
        // TODO: Implement deleteMultiple() method.
    }

    /**
     * Проверяет наличие ключа
     * @param string $key
     * @return bool
     */
    public function has($key): bool
    {
        return file_exists($this->cachePath . DIRECTORY_SEPARATOR . sha1($key));
    }

    /**
     * Получить мета - данные ключа
     * @param $key
     * @return array
     */
    private function getMetaData($key): array
    {
        if (isset($this->meta[$k = sha1($key)]) && is_array($this->meta[$k])) return $this->meta[$k];
        return [];
    }

    /**
     * Обслуживание кэша
     */
    private function cleanUp(): void
    {
        //TODO Сделать обслуживание
    }

    public function __destruct()
    {
        $this->cleanUp();
        file_put_contents($this->cachePath . DIRECTORY_SEPARATOR . self::SERVICE_FILE,
            $this->meta ? json_encode($this->meta) : '');
    }
}