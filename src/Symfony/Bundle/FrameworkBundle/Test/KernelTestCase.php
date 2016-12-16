<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Test;

use Symfony\Bundle\FrameworkBundle\Tests\Functional\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Tests\Functional\Kernel\TestKernelInterface;
use Symfony\Component\DependencyInjection\ResettableContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * KernelTestCase is the base class for tests needing a Kernel.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
abstract class KernelTestCase extends \PHPUnit_Framework_TestCase
{
    protected static $class;

    /**
     * @var KernelInterface
     */
    protected static $kernel;

    /**
     * Finds the directory where the phpunit.xml(.dist) is stored.
     *
     * If you run tests with the PHPUnit CLI tool, everything will work as expected.
     * If not, override this method in your test classes.
     *
     * @return string The directory where phpunit.xml(.dist) is stored
     *
     * @throws \RuntimeException
     */
    protected static function getPhpUnitXmlDir()
    {
        if (!isset($_SERVER['argv']) || false === strpos($_SERVER['argv'][0], 'phpunit')) {
            throw new \RuntimeException('You must override the KernelTestCase::createKernel() method.');
        }

        $dir = static::getPhpUnitCliConfigArgument();
        if (null === $dir &&
            (is_file(getcwd().DIRECTORY_SEPARATOR.'phpunit.xml') ||
            is_file(getcwd().DIRECTORY_SEPARATOR.'phpunit.xml.dist'))) {
            $dir = getcwd();
        }

        // Can't continue
        if (null === $dir) {
            throw new \RuntimeException('Unable to guess the Kernel directory.');
        }

        if (!is_dir($dir)) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    /**
     * Finds the value of the CLI configuration option.
     *
     * PHPUnit will use the last configuration argument on the command line, so this only returns
     * the last configuration argument.
     *
     * @return string The value of the PHPUnit CLI configuration option
     */
    private static function getPhpUnitCliConfigArgument()
    {
        $dir = null;
        $reversedArgs = array_reverse($_SERVER['argv']);
        foreach ($reversedArgs as $argIndex => $testArg) {
            if (preg_match('/^-[^ \-]*c$/', $testArg) || $testArg === '--configuration') {
                $dir = realpath($reversedArgs[$argIndex - 1]);
                break;
            } elseif (0 === strpos($testArg, '--configuration=')) {
                $argPath = substr($testArg, strlen('--configuration='));
                $dir = realpath($argPath);
                break;
            } elseif (0 === strpos($testArg, '-c')) {
                $argPath = substr($testArg, strlen('-c'));
                $dir = realpath($argPath);
                break;
            }
        }

        return $dir;
    }

    /**
     * Attempts to guess the kernel location.
     *
     * When the Kernel is located, the file is required.
     *
     * @return string The Kernel class name
     *
     * @throws \RuntimeException
     */
    protected static function getKernelClass()
    {
        // check kernel class specified explicitly in phpunit.xml/phpunit.xml.dist
        if (isset($_SERVER['KERNEL_CLASS'])) {
            $kernelClass = $_SERVER['KERNEL_CLASS'];
            if (!class_exists($kernelClass)) {
                throw new \RuntimeException(sprintf('Test kernel class "%s" specified by KERNEL_CLASS in phpunit.xml does not exist', $_SERVER['KERNEL_CLASS']));
            }

            return $kernelClass;
        }

        // try to find kernel class in specified directory in phpunit.xml/phpunit.xml.dist
        if (isset($_SERVER['KERNEL_DIR'])) {
            $dir = $_SERVER['KERNEL_DIR'];

            if (!is_dir($dir)) {
                $phpUnitDir = static::getPhpUnitXmlDir();
                if (is_dir("$phpUnitDir/$dir")) {
                    $dir = "$phpUnitDir/$dir";
                }
            }

            if ($kernelClass = static::findKernelClassInDirectory($dir)) {
                throw new \RuntimeException(sprintf('There is no test kernel class in specified KERNEL_DIR "%s"', $_SERVER['KERNEL_CLASS']));
            }

            return $kernelClass;
        }

        // try to find kernel class near phpunit.xml/phpunit.xml.dist files
        if ($kernelClass = static::findKernelClassInDirectory(static::getPhpUnitXmlDir())) {
            return $kernelClass;
        }

        // fallback to default kernel class
        return static::getDefaultTestKernelClass();
    }

    /**
     * Tries to find kernel class in directory. Returns null if no appropriative class found
     *
     * @param string $dir directory to be processed
     *
     * @return string|null
     */
    protected static function findKernelClassInDirectory($dir)
    {
        $kernelClass = null;

        $finder = new Finder();
        $finder->name('*Kernel.php')->depth(0)->in($dir);
        $results = iterator_to_array($finder);
        if (count($results)) {
            $file = current($results);

            $classes = get_declared_classes();
            require_once $file;
            $newClasses = array_diff(get_declared_classes(), $classes);
            if ($newClasses) {
                $kernelClass = reset($newClasses);
            }
        }

        return $kernelClass;
    }

    /**
     * Returns default test kernel class FQCN
     *
     * @var string
     */
    protected static function getDefaultTestKernelClass()
    {
        return TestKernel::class;
    }

    /**
     * Boots the Kernel for this test.
     *
     * @param array $options
     */
    protected static function bootKernel(array $options = array())
    {
        static::ensureKernelShutdown();

        static::$kernel = static::createKernel($options);
        static::$kernel->boot();
    }

    /**
     * Creates a Kernel.
     *
     * Available options:
     *
     *  * environment
     *  * debug
     *
     * @param array $options An array of options
     *
     * @return KernelInterface A KernelInterface instance
     */
    protected static function createKernel(array $options = array())
    {
        if (null === static::$class) {
            static::$class = static::getKernelClass();
        }

        if (isset($_SERVER['KERNEL_ENV'])) {
            $options['environment'] = $_SERVER['KERNEL_ENV'];
        }

        if (isset($_SERVER['KERNEL_DEBUG'])) {
            $options['debug'] = $_SERVER['KERNEL_DEBUG'];
        }

        $kernel = new static::$class(
            isset($options['environment']) ? $options['environment'] : 'test',
            isset($options['debug']) ? $options['debug'] : true
        );

        if ($kernel instanceof TestKernelInterface) {
            if (!isset($options['test_case'])) {
                throw new \InvalidArgumentException('The option "test_case" must be set.');
            }
            if (!isset($options['config_dir'])) {
                throw new \InvalidArgumentException('The option "config_dir" must be set.');
            }

            $kernel->setTestKernelConfiguration(
                $options['test_case'],
                $options['config_dir'],
                isset($options['root_config']) ? $options['root_config'] : 'config.yml',
                isset($options['root_dir']) ? $options['root_dir'] : null);
        }

        return $kernel;
    }

    /**
     * Shuts the kernel down if it was used in the test.
     */
    protected static function ensureKernelShutdown()
    {
        if (null !== static::$kernel) {
            $container = static::$kernel->getContainer();
            $kernel    = static::$kernel;
            $kernel->shutdown();
            if ($container instanceof ResettableContainerInterface) {
                $container->reset();
            }

            if ($kernel instanceof TestKernelInterface) {
                $fs = new Filesystem();
                $fs->remove($kernel->getTempAppDir());
            }
        }
    }

    /**
     * Clean up Kernel usage in this test.
     */
    protected function tearDown()
    {
        static::ensureKernelShutdown();
    }
}
