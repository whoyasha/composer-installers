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
    protected $frameworkInstaller;
    
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
    
    protected function getFrameworkInstaller(PackageInterface $package) {
        $class = 'Composer\\Installers\\' . $this->supportedTypes[$this->frameworkType];
        return new $class($package, $this->composer, $this->getIO());
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $type = $package->getType();
        
        file_put_contents("/home/bitrix/composer.txt", $type);
        $this->frameworkType = $this->findFrameworkType($type);

        if ($this->frameworkType === false) {
            throw new \InvalidArgumentException(
                'Sorry the package type of this package is not yet supported.'
            );
        }
        
        $this->frameworkInstaller = $this->getFrameworkInstaller($package);
        $this->path = $this->frameworkInstaller->getInstallPath($package, $this->frameworkType);
        
        if (!$this->filesystem->isAbsolutePath($this->path)) {
            $path = getcwd() . '/' . $this->path;
        }

        return $this->path;
    }
    
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
    
        $promise = parent::install($repo, $package);
            
        if ( $this->frameworkType == "bitrix" ) {

            $callback = function () use ($repo, $package) {
                
                $module = $this->frameworkInstaller->GetModuleSettings();

                if ( is_dir($this->path) ) {
                    
                    $git = $this->path . "/.git";
                    $composer_json = $this->path . "/composer.json";
                    // $bx_utils = "";
                    
                    if ( is_dir($git) )
                        $this->filesystem->removeDirectory($git);
                        
                    if ( file_exists($composer_json) )
                        $this->filesystem->unlink($composer_json);
                        
                    if ( isset($module["libs"]) && count($module["libs"]) > 0 )
                        foreach ( $module["libs"] as $lib ) {
                            
                            if ( $lib == "utils" )
                                continue;
                                
                            $this->addLibsStruct($lib, $module);
                        }
                            
                    if ( isset($module["lang_files"]) && is_array($module["lang_files"]) && count($module["lang_files"]) > 0 )
                        $this->addModuleLangFiles($module["lang_files"]);
                    
                } else {
                    throw new \Exception('Module path not defined');
                }
            };

            if ($promise instanceof PromiseInterface) {
                return $promise->then($callback);
            }
            
            $callback();
        }
    }
    
    protected function addModuleLangFiles($langs) {
        
        $langs_dir = $this->path . "/lang";
        
        if ( !is_dir($langs_dir) )
            \mkdir($langs_dir);
        
        foreach ( $langs as $lang ) {
            $lang_dir = $langs_dir . "/" . $lang;
            $lang_include_php = $lang_dir . "/include.php";
            
            if ( !is_dir($lang_dir) )
                \mkdir($lang_dir);
            
            if ( !file_exists($lang_include_php) ) {
                $content = "<?php if (!defined(\"B_PROLOG_INCLUDED\") || B_PROLOG_INCLUDED!==true) die();" . PHP_EOL;
                $content .= "\$MESS[\"YOU_MESSAGE_CODE\"]=\"\";" . PHP_EOL;
                $content .= "?>" . PHP_EOL;
                
                file_put_contents($lang_include_php, $content);
            }
        }
        
        $include_php = $this->path . "/include.php";
        
        if ( file_exists($include_php) ) {
            $include_content = file_get_contents($include_php);
            $lang_content = "<?php" . PHP_EOL;
            $lang_content .= "use \Bitrix\Main\Localization\Loc;" . PHP_EOL;
            $lang_content .= "Loc::loadLanguageFile(__FILE__, LANGUAGE_ID);" . PHP_EOL;
            $new_content = str_replace("<?php", $lang_content, $include_content);

            file_put_contents($include_php, $new_content);
        }
    }
    
    protected function addLibsStruct($lib, $module) {
        $vendor = '';
        if ( !empty($module['vendor']) )
            $vendor = "/" . \ucwords($module['vendor']);
        
        $dir = $this->path . "/" . $lib;
        $vendor = $dir . $vendor;
        
        $module_name = $this->dashesToCamelCase(str_replace($module['vendor'] . "_", "", $module["name"]));
        $name = $vendor . "/" . $module_name;
        
        if ( !is_dir($dir) )
            \mkdir($dir);
            
        if ( !is_dir($vendor) )
            \mkdir($vendor);
            
        if ( !is_dir($name) )
            \mkdir($name);
        
        if ( !file_exists($name . '/MyClass.php') ) {
            $file_content = "<?php \r\nnamespace " . ucwords($module['vendor']) . "\\" . $module_name . ";\r\n\r\n" . "Class MyClass {\r\n\r\n}\r\n\r\n?>";
            file_put_contents($name . "/MyClass.php", $file_content);
        }
    }
    
    public function dashesToCamelCase($string, $divider = "_") {
        $string = preg_replace('/[\'\/~`\!@#\$%\^&\*\+=\{\}\[\]\|;:"\<\>,\.\?\\\]/', "", $string);
        $str = str_replace($divider, '', \ucwords($string, $divider));
        return $str;
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
            
            $pkg = new Package('dummy/pkg', '1.0.0.0', '1.0.0');
            
            $framework = new $frameworkClass($pkg, $this->composer, $this->getIO());
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
