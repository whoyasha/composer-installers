<?php

namespace Composer\Installers;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

class Installer extends LibraryInstaller
{
    protected $path;
    protected $frameworkType;
    protected $module_settings;
    
    /**
     * Package types to installer class map
     *
     * @var array<string, string>
     */
    private $supportedTypes = array(
        'bitrix'       => 'BitrixInstaller',
    );

    /**
     * Disables installers specified in main composer extra installer-disable
     * list
     */
    public function __construct(
        IOInterface $io,
        Composer $composer,
        string $type = 'library',
        ?Filesystem $filesystem = null,
        ?BinaryInstaller $binaryInstaller = null
    ) {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
        $this->removeDisabledInstallers();
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $type = $package->getType();
        $frameworkType = $this->findFrameworkType($type);

        if ($frameworkType === false) {
            throw new \InvalidArgumentException(
                'Sorry the package type of this package is not yet supported.'
            );
        }
        
        $this->frameworkType = $frameworkType;

        $class = 'Composer\\Installers\\' . $this->supportedTypes[$frameworkType];
        $installer = new $class($package, $this->composer, $this->getIO());

        $path = $installer->getInstallPath($package, $frameworkType);
        $this->path = $path;
        
        if (!$this->filesystem->isAbsolutePath($path)) {
            $path = getcwd() . '/' . $path;
        }

        return $path;
    }
    
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
    
        $promise = parent::install($repo, $package);
            
        if ( $this->frameworkType == "bitrix" ) {
            
            $callback = function () use ($repo, $package) {
                
                $bitrix_dir = preg_match("/(local)/", $this->path) ? "local" : "bitrix";
                $document_root = realpath(explode("/" . $bitrix_dir . "/", $this->path)[0]);
                
                $module_id = str_replace(["modules", "/"], "", explode("/" . $bitrix_dir, $this->path)[1]);
                $module_path = $document_root . "/" . $bitrix_dir . "/modules/" . $module_id;
                $install_path = $module_path . "/install/settings.php"

                if ( file_exists($module_path) ) {
                    // $this->initBitrix($document_root);
                    
                    $git = $module_path . "/.git";
                    $cjson = $module_path . "/composer.json";
                    
                    $this->removeDir($git);
                    unlink($cjson);
                } else {
                    throw new \Exception('Module path not defined');
                }
            };
            
            // Composer v2 might return a promise here
            if ($promise instanceof PromiseInterface) {
                return $promise->then($callback);
            }
            
            $callback();
        }
    }
    
    protected function removeDir($dir)
    {
        if ($objs = glob($dir . '/*')) {
            foreach($objs as $obj) {
                is_dir($obj) ? $this->removeDir($obj) : unlink($obj);
            }
        }
        rmdir($dir);
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $installPath = $this->getPackageBasePath($package);
        $io = $this->io;
        $outputStatus = function () use ($io, $installPath) {
            $io->write(sprintf('Deleting %s - %s', $installPath, !file_exists($installPath) ? '<comment>deleted</comment>' : '<error>not deleted</error>'));
        };

        $promise = parent::uninstall($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($outputStatus);
        }

        // If not, execute the code right away as parent::uninstall executed synchronously (composer v1, or v2 without async)
        $outputStatus();

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        $frameworkType = $this->findFrameworkType($packageType);

        if ($frameworkType === false) {
            return false;
        }

        $locationPattern = $this->getLocationPattern($frameworkType);

        return preg_match('#' . $frameworkType . '-' . $locationPattern . '#', $packageType, $matches) === 1;
    }

    /**
     * Finds a supported framework type if it exists and returns it
     *
     * @return string|false
     */
    protected function findFrameworkType(string $type)
    {
        krsort($this->supportedTypes);

        foreach ($this->supportedTypes as $key => $val) {
            if ($key === substr($type, 0, strlen($key))) {
                return substr($type, 0, strlen($key));
            }
        }

        return false;
    }

    /**
     * Get the second part of the regular expression to check for support of a
     * package type
     */
    protected function getLocationPattern(string $frameworkType): string
    {
        $pattern = null;
        if (!empty($this->supportedTypes[$frameworkType])) {
            $frameworkClass = 'Composer\\Installers\\' . $this->supportedTypes[$frameworkType];
            /** @var BaseInstaller $framework */
            $framework = new $frameworkClass(new Package('dummy/pkg', '1.0.0.0', '1.0.0'), $this->composer, $this->getIO());
            $locations = array_keys($framework->getLocations($frameworkType));
            if ($locations) {
                $pattern = '(' . implode('|', $locations) . ')';
            }
        }

        return $pattern ?: '(\w+)';
    }

    private function getIO(): IOInterface
    {
        return $this->io;
    }

    /**
     * Look for installers set to be disabled in composer's extra config and
     * remove them from the list of supported installers.
     *
     * Globals:
     *  - true, "all", and "*" - disable all installers.
     *  - false - enable all installers (useful with
     *     wikimedia/composer-merge-plugin or similar)
     */
    protected function removeDisabledInstallers(): void
    {
        $extra = $this->composer->getPackage()->getExtra();

        if (!isset($extra['installer-disable']) || $extra['installer-disable'] === false) {
            // No installers are disabled
            return;
        }

        // Get installers to disable
        $disable = $extra['installer-disable'];

        // Ensure $disabled is an array
        if (!is_array($disable)) {
            $disable = array($disable);
        }

        // Check which installers should be disabled
        $all = array(true, "all", "*");
        $intersect = array_intersect($all, $disable);
        if (!empty($intersect)) {
            // Disable all installers
            $this->supportedTypes = array();
            return;
        }

        // Disable specified installers
        foreach ($disable as $key => $installer) {
            if (is_string($installer) && key_exists($installer, $this->supportedTypes)) {
                unset($this->supportedTypes[$installer]);
            }
        }
    }
}
