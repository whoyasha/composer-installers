<?php

namespace Composer\Installers;

use Composer\Util\Filesystem;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use React\Promise\PromiseInterface;

/**
 * Installer for Bitrix Framework. Supported types of extensions:
 * - `bitrix-d7-module` — copy the module to directory `bitrix/modules/<vendor>.<name>`.
 *
 * You can set custom path to directory with Bitrix kernel in `composer.json`:
 *
 * ```json
 * {
 *      "extra": {
 *          "bitrix-dir": "s1/bitrix"
 *      }
 * }
 * ```
 *
 * @author Nik Samokhvalov <nik@samokhvalov.info>
 * @author Denis Kulichkin <onexhovia@gmail.com>
 */
 
 //  не удаляет предпоследний и раннее модули
 
class BitrixInstaller extends BaseInstaller
{
	private $module_settings = [];
	
	/** @var array<string, string> */
	protected $locations = array(
		'd7-module' => '{$bitrix_dir}/modules/{$vendor}_{$name}/'
	);

	/**
	 * @var string[] Storage for informations about duplicates at all the time of installation packages.
	 */
	private static $checkedDuplicates = array();
	
	public function GetModuleSettings() {
		return $this->module_settings;
	}

	public function inflectPackageVars(array $vars): array
	{
		/** @phpstan-ignore-next-line */
		if ($this->composer->getPackage()) {
			$extra = $this->composer->getPackage()->getExtra();

			if (isset($extra['bitrix-dir'])) {
				$vars['bitrix_dir'] = $extra['bitrix-dir'];
			}
			
			if (isset($extra['installer-vendor'])) {
				$vars['vendor'] = $extra['installer-vendor'];
			}

			if (isset($extra['modules'])) {
				$vars['name'] = NULL;
				foreach ( $extra['modules'] as $module ) {
					
					if ( $module['current'] ) {
						
						$vars['name'] = $module['name'];
						$vars['site_path'] = $module['site_path'];
						
						if ( isset($module["vendor"]) )
							$vars['vendor'] = $module['vendor'];
						
						unset($module["current"], $module['site_path']);
						$module['vendor'] = $vars['vendor'];
						$this->module_settings = $module;

						break;
					}
				}
				
				if ( is_null($vars['name']) ) {
					throw new \Exception('Current item not defined');
				}
			} elseif (!empty($extra['installer-name'])) {
				$vars['name'] = $extra['installer-name'];
			}
		}

		if ( isset($vars['site_path']) ) {
			$vars['bitrix_dir'] = $vars['site_path'] . '/local';
		} elseif ( !isset($vars['bitrix_dir']) ) {
			$vars['bitrix_dir'] = $extra['bitrix_dir'];
		} else {
			$vars['bitrix_dir'] = "www/local";
		}

		return parent::inflectPackageVars($vars);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function templatePath(string $path, array $vars = array()): string
	{
		$templatePath = parent::templatePath($path, $vars);
		$this->checkDuplicates($templatePath, $vars);

		return $templatePath;
	}

	/**
	 * Duplicates search packages.
	 *
	 * @param array<string, string> $vars
	 */
	protected function checkDuplicates(string $path, array $vars = array()): void
	{
		$packageType = substr($vars['type'], strlen('bitrix') + 1);

		$oldPath = str_replace(
			array('{$bitrix_dir}', '{$vendor}', '{$name}'),
			array($vars['bitrix_dir'], $vars['vendor'], $vars['name']),
			$this->locations[$packageType]
		);

		if (in_array($oldPath, static::$checkedDuplicates)) {
			return;
		}

		if ($oldPath !== $path && file_exists($oldPath) && $this->io->isInteractive()) {
			$this->io->writeError('    <error>Duplication of packages:</error>');
			$this->io->writeError('    <info>Package ' . $oldPath . ' will be called instead package ' . $path . '</info>');

			while (true) {
				switch ($this->io->ask('    <info>Delete ' . $oldPath . ' [y,n,?]?</info> ', '?')) {
					case 'y':
						$fs = new Filesystem();
						$fs->removeDirectory($oldPath);
						break 2;

					case 'n':
						break 2;

					case '?':
					default:
						$this->io->writeError(array(
							'    y - delete package ' . $oldPath . ' and to continue with the installation',
							'    n - don\'t delete and to continue with the installation',
						));
						$this->io->writeError('    ? - print help');
						break;
				}
			}
		}

		static::$checkedDuplicates[] = $oldPath;
	}
	
}
