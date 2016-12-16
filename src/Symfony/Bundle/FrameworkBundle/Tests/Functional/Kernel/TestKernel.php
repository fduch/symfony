<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Functional\Kernel;

// trying to find autoload file for case when kernel is deserialised without explicit autoloader specified
$dir = __DIR__;
$lastDir = null;
while ($dir !== $lastDir) {
    $lastDir = $dir;

    if (is_file($dir.'/autoload.php')) {
        require_once $dir.'/autoload.php';
        break;
    }

    if (is_file($dir.'/autoload.php.dist')) {
        require_once $dir.'/autoload.php.dist';
        break;
    }

    if (file_exists($dir.'/vendor/autoload.php')) {
        require_once $dir.'/vendor/autoload.php';
        break;
    }

    $dir = dirname($dir);
}

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * Kernel for functional / integration tests
 *
 * @author fduch alex.medwedew@gmail.com
 */
class TestKernel extends Kernel implements TestKernelInterface
{
    /**
     * Directory holds test cases configs
     *
     * @var string
     */
    protected $configDir;

    /**
     * Directory name where kernel configs are stored
     *
     * @var string
     */
    protected $testCase;

    /**
     * Relative (to the $testCase) path to main test case config file
     *
     * @var string
     */
    protected $rootConfig;

    /**
     * Filesystem
     *
     * @var Filesystem
     */
    private $fs;

    /**
     * If $rootDir is not provided, it would be set to $configDir
     *
     * Attention! If $rootDir is provided but is not exist, it would be created.
     * Don't forget to clean it by yourself after test run if it holds any temporary data.
     *
     * {@inheritdoc}
     */
    public function setTestKernelConfiguration($testCase, $configDir, $rootConfig, $rootDir = null)
    {
        $this->fs = new Filesystem();

        if (!$rootDir) {
            $rootDir = $configDir;
        }

        if (!is_dir($rootDir)) {
            $this->fs->mkdir($rootDir);
        }
        $this->rootDir = realpath($rootDir);

        $this->configDir = realpath($configDir);
        if (!is_dir($this->configDir . DIRECTORY_SEPARATOR . $testCase)) {
            throw new \InvalidArgumentException(sprintf('The test case "%s" does not exist.', $testCase));
        }
        $this->testCase = $testCase;

        if (!$this->fs->isAbsolutePath($rootConfig) &&
            !file_exists($rootConfig = implode(DIRECTORY_SEPARATOR, [$this->configDir, $testCase, $rootConfig]))) {
            throw new \InvalidArgumentException(sprintf('The root config "%s" does not exist.', $rootConfig));
        }

        $this->rootConfig = $rootConfig;
        $this->name = preg_replace('/[^a-zA-Z0-9_]+/', '', basename($this->configDir).str_replace(DIRECTORY_SEPARATOR, "_", $testCase)).uniqid();
    }

    /**
     * {@inheritdoc}
     */
    public function registerBundles()
    {
        $additionalBundles = [];
        $baseBundles = $this->getBaseBundles();
        if (file_exists($filename = implode(DIRECTORY_SEPARATOR, [$this->configDir, $this->testCase, 'bundles.php']))) {
            $additionalBundles = include $filename;
            if (!is_array($additionalBundles)) {
                throw new \RuntimeException(sprintf('It is required to return array of bundles from "%s" file', $filename));
            }
        }
        return array_merge($baseBundles, $additionalBundles);
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheDir()
    {
        return implode(DIRECTORY_SEPARATOR, [$this->getTempAppDir(), $this->testCase, 'cache', $this->environment]);
    }

    /**
     * {@inheritdoc}
     */
    public function getLogDir()
    {
        return implode(DIRECTORY_SEPARATOR, [$this->getTempAppDir(), $this->testCase, 'logs']);
    }

    /**
     * {@inheritdoc}
     */
    public function getTempAppDir()
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load($this->rootConfig);
    }

    /**
     * {@inheritdoc}
     */
    public function serialize()
    {
        return serialize(
            array(
                $this->getEnvironment(),
                $this->isDebug(),
                $this->testCase,
                $this->configDir,
                $this->rootConfig,
                $this->rootDir,
                $this->name));
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($str)
    {
        list($env, $debug, $testCase, $configDir, $rootConfig, $rootDir, $name) = unserialize($str);
        $this->__construct($env, $debug);
        $this->setTestKernelConfiguration($testCase, $configDir, $rootConfig, $rootDir);
        // restore original name to prevent regeneration
        $this->name = $name;
    }

    /**
     * Returns base bundle list
     *
     * @return array
     */
    protected function getBaseBundles()
    {
        return [new FrameworkBundle()];
    }

    /**
     * {@inheritdoc}
     */
    protected function getKernelParameters()
    {
        $parameters = parent::getKernelParameters();

        $parameters['kernel.test_case']  = $this->testCase;
        $parameters['kernel.config_dir'] = $this->configDir;

        return $parameters;
    }
}
