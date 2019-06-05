<?php

/*
 * This file is part of the doyo/behat-coverage-extension project.
 *
 * (c) Anthonius Munthi <me@itstoni.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Doyo\Behat\Coverage\Bridge\CodeCoverage;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class Cache implements \Serializable
{
    const CACHE_KEY = 'subject';

    /**
     * @var string|null
     */
    private $namespace;

    /**
     * @var string|null
     */
    private $coverageId;

    /**
     * @var FilesystemAdapter|null
     */
    private $adapter;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var array
     */
    private $filter = [];

    /**
     * @var CodeCoverage
     */
    private $codeCoverage;

    /**
     * @var array
     */
    private $codeCoverageOptions = [];

    /**
     * @var \Exception[]
     */
    private $exceptions = [];

    public function __construct($namespace)
    {
        $dir             = sys_get_temp_dir().'/doyo/behat-coverage-extension';
        $adapter         = new FilesystemAdapter($namespace, 0, $dir);
        $this->adapter   = $adapter;
        $this->namespace = $namespace;

        $this->readCache();
    }

    public function reset()
    {
        $this->coverageId = null;
        $this->data   = [];
        $this->exceptions = [];

        $this->save();
    }

    public function serialize()
    {
        $data = [
            $this->coverageId,
            $this->data,
            $this->codeCoverageOptions,
            $this->filter
        ];

        return serialize($data);
    }

    public function unserialize($serialized)
    {
        list(
            $this->coverageId,
            $this->data,
            $this->codeCoverageOptions,
            $this->filter
        ) = unserialize($serialized);
    }

    /**
     * @return string|null
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return string|null
     */
    public function getCoverageId()
    {
        return $this->coverageId;
    }

    /**
     * @param string|null $coverageId
     *
     * @return Cache
     */
    public function setCoverageId(string $coverageId): self
    {
        $this->coverageId = $coverageId;

        return $this;
    }

    /**
     * @return FilesystemAdapter|null
     */
    public function getAdapter(): FilesystemAdapter
    {
        return $this->adapter;
    }

    /**
     * @param FilesystemAdapter|null $adapter
     *
     * @return Cache
     */
    public function setAdapter(FilesystemAdapter $adapter): self
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array $data
     *
     * @return Cache
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @return array
     */
    public function getCodeCoverageOptions(): array
    {
        return $this->codeCoverageOptions;
    }

    /**
     * @param array $codeCoverageOptions
     *
     * @return Cache
     */
    public function setCodeCoverageOptions(array $codeCoverageOptions): self
    {
        $this->codeCoverageOptions = $codeCoverageOptions;

        return $this;
    }

    public function getFilter(): array
    {
        return $this->filter;
    }

    /**
     * @param Filter|array $filter
     *
     * @return $this
     */
    public function setFilter($filter)
    {
        if ($filter instanceof Filter) {
            $whitelistedFiles              = $filter->getWhitelistedFiles();
            $filter                        = [];
            $filter['addFilesToWhitelist'] = $whitelistedFiles;
        }

        $this->filter = $filter;

        return $this;
    }

    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function save()
    {
        $adapter = $this->adapter;
        $item    = $adapter->getItem(static::CACHE_KEY);

        $item->set($this);
        $adapter->save($item);
    }

    public function readCache()
    {
        $adapter = $this->adapter;
        $cached  = $adapter->getItem(static::CACHE_KEY)->get();

        if ($cached instanceof self) {
            $this->coverageId = $cached->getCoverageId();
            $this->data   = $cached->getData();
        }
    }

    /**
     * @return Filter
     */
    public function createFilter()
    {
        $config = $this->filter;
        $filter = new Filter();
        foreach ($config as $method => $value) {
            \call_user_func_array([$filter, $method], [$value]);
        }

        return $filter;
    }

    public function startCoverage($driver = null)
    {
        if (null === $this->coverageId) {
            return;
        }
        try {
            $coverage = $this->createCodeCoverage($driver);
            $coverage->start($this->getCoverageId());
            register_shutdown_function([$this, 'shutdown']);
        } catch (\Exception $e) {
            $this->exceptions[] = sprintf(
                "Can not start coverage in namespace: %s :\n%s",
                $this->namespace,
                $e->getMessage()
            );
        }
    }

    public function shutdown()
    {
        $codeCoverage = $this->codeCoverage;

        if (null === $codeCoverage) {
            return;
        }

        $data = $codeCoverage->stop();
        $this->data = $data;
        $this->codeCoverage = null;

        $this->save();
    }

    /**
     * @param mixed|null $driver
     *
     * @return CodeCoverage
     */
    private function createCodeCoverage($driver = null)
    {
        $coverage = $this->codeCoverage;
        $filter   = $this->createFilter();
        $options  = $this->codeCoverageOptions;

        if(is_null($coverage)){
            $coverage = new CodeCoverage($driver, $filter);
            $this->codeCoverage = $coverage;
        }

        foreach ($options as $method => $option) {
            $method = 'set'.$method;
            if (method_exists($coverage, $method)) {
                \call_user_func_array([$coverage, $method], [$option]);
            }
        }

        return $coverage;
    }
}
