<?php namespace FMLaravel\Database\ContainerField;

use FileMaker;
use FMLaravel\Database\Model;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Util\MimeType;
use Illuminate\Support\Facades\Cache;

class ContainerField
{
    /** A link to the containing model
     * @var Model
     */
    protected $model;

    /** Denotes the origin where the current data originates from
     * @var string
     */
    protected $origin;

    /**
     * @var string
     */
    protected $key;

    /** Contains container data;
     * @var array
     */
    protected $container = [];



    protected function __construct($origin)
    {
        $this->origin = $origin;
    }

    /**
     * @param $key
     * @param $resource
     * @param Connection|null $connection
     * @return ContainerField
     */
    public static function fromServer($key, $url, Model $model)
    {
        $cf = new ContainerField('server');

        $cf->key = $key;
        $cf->model = $model;

        $filename = basename(substr($url, 0, strpos($url, '?')));

        $cf->container = [
            'file'    => $filename,
            'url'     => $url
        ];

        return $cf;
    }

    public static function fromStorage($filename, $disk = null)
    {
        $cf = new ContainerField('storage');

        $cf->setFromStorage($filename, $disk);

        return $cf;
    }

    public function setFromStorage($filename, $disk = null)
    {
        $this->origin = 'storage';
        $this->container = [
            'file'      => $filename,
            'disk'      => $disk
        ];
    }

    public static function fromRealpath($realpath, $filename = null)
    {
        $cf = new ContainerField('realpath');

        $cf->setFromRealpath($realpath, $filename);

        return $cf;
    }

    public function setFromRealpath($realpath, $filename = null)
    {
        if ($filename === null) {
            $filename = basename($realpath);
        }

        $this->origin = 'realpath';
        $this->container = [
            'file'      => $filename,
            'realpath'  => $realpath
        ];
    }

    public static function withData($filename, $rawData)
    {
        $cf = new ContainerField('data');

        $cf->setWithData($filename, $rawData);

        return $cf;
    }

    public function setWithData($filename, $rawData)
    {
        $this->origin = 'data';
        $this->container = [
            'file'      => $filename,
            'data'      => $rawData
        ];
    }



    public function getModel()
    {
        return $this->model;
    }
    public function setModel(Model $model)
    {
        $this->model = $model;
        return $this;
    }
    public function getKey()
    {
        return $this->key;
    }
    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }

    public function getMimeType()
    {
        return MimeType::detectByFilename($this->container['file']);
    }


    public function __get($name)
    {
        // container field data is treated specially
        if ($name == 'data') {
            switch ($this->origin) {
                case 'server':
                    // return null if no url/container data exists
                    if (empty($this->container['url'])) {
                        return null;
                    }
                    if (!$this->hasLoadedServerData()) {
                        // if cache is enabled, check it first, and possibly retrieve server
                        if ($this->isCachable()) {
                            $key = $this->getCacheKey();
                            $store = $this->model->getContainerFieldCacheStore();

                            if ($store->has($key)) {
                                $this->container['data'] = $store->get($key);
                            } else {
                                $this->loadServerData();
                                $this->saveToCache();
                            }
                        } else { // no cache used.
                            $this->loadServerData();
                        }
                    }

                    return $this->container['data'];

                case 'realpath':
                    return file_get_contents($this->container['realpath']);

                case 'storage':
                    return Storage::disk($this->container['disk'])->get($this->container['file']);

                case 'data':
                    return $this->container['data'];
            }
        } elseif (isset($this->container[$name])) {
            return $this->container[$name];
        }
    }

    /** Is content set?
     * NOTE: only meaningful for fields fetched from the server
     * @return bool
     */
    public function isEmpty()
    {
        switch ($this->origin) {
            case 'server':
                return empty($this->container['url']);

            // in case
            default:
                return false;
        }
    }

    public function hasLoadedServerData()
    {
        return $this->origin == 'server' && array_key_exists('data', $this->container);
    }

    public function loadServerData()
    {
        if (!$this->hasLoadedServerData()) {
            $this->container['data'] = $this->fetchServerData();
        }
    }
    protected function fetchServerData()
    {
        if ($this->origin != 'server') {
            throw new Exception("Container data is not stored on server");
        }
        if (empty($this->container['url'])) {
            return null;
        }
        return $this->model->getConnection()->filemaker('read')->getContainerData($this->container['url']);
    }

    public function didSaveToServer($url)
    {

        $this->container['url'] = $url;

        if ($this->isCachable()) {
            $this->saveToCache();
        }
    }


    /**
     * ONLY to be use
     * @return string|null
     */
    public function getCacheKey()
    {
        if (array_key_exists('url', $this->container)) {
            return $this->container['url'];
        }
        return null;
    }

    /**
     * to use the cache, it must be enabled, and a record it (as retrieved from the server) must be set
     * @return bool
     */
    public function isCachable()
    {
        return 0 < $this->model->getContainerFieldCacheTime() && !empty($this->getCacheKey());
    }

    protected function saveToCache()
    {
        switch ($this->origin) {
            case 'server':
            case 'data':
                $data = $this->container['data'];
                break;

            case 'realpath':
                $data = file_get_contents($this->container['realpath']);
                break;

            case 'storage':
                $data = Storage::disk($this->container['disk'])->get($this->container['file']);
                break;

            default:
                throw new Exception("origin not supported {$this->origin}");
        }
        $this->model->getContainerFieldCacheStore()->put(
            $this->getCacheKey(),
            $data,
            $this->model->getContainerFieldCacheTime()
        );
    }
}
