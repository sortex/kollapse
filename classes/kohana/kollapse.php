<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Handles asset packaging of scripts and stylesheets. Also provides helper functions
 * for including assets in views.
 *
 * MODIFIED: Rafi <justrafi@gmail.com>
 *
 * @package    Kollapse
 * @author     Gabriel Evans <gabriel@codeconcoction.com>
 * @copyright  (c) 2010 Gabriel Evans
 * @license    http://www.opensource.org/licenses/mit-license.php MIT license
 */

abstract class Kohana_Kollapse {

	/**
	 * @var array|null  Configuration
	 */
	protected static $config = NULL;

	/**
	 * @var Kollapse Driver instance
	 */
	protected static $driver;

	/**
	 * @var array    Filter instances
	 */
	protected static $filters = array();

	/**
	 * @var array    File timestamps
	 */
	protected static $timestamps;

	/**
	 * Stores configuration locally and instantiates compression driver
	 *
	 * @static
	 * @throws Kohana_Exception
	 * @param  array|null $config
	 * @param  bool       $driver_instance
	 * @param  bool       $filter_instance
	 * @return void
	 */
	protected static function init(array $config = NULL, $driver_instance = TRUE, $filter_instance = TRUE)
	{
		if ($config === NULL)
		{
			$config = (array) Kohana::config('kollapse');
		}

		$config = array_merge(array(
			'packaging' => (Kohana::$environment != 'development') ? TRUE : FALSE,
			'gzip_compression' => FALSE,
			'driver' => 'minify',
		), $config);

		if ($config['packaging'] == 'off')
		{
			$config['packaging'] = FALSE;
		}
		elseif ($config['packaging'] == 'always')
		{
			$config['packaging'] = TRUE;
		}

		if ( ! isset($config['package_paths']['javascripts']))
		{
			throw new Kohana_Exception('Javascripts path not set');
		}

		if ( ! isset($config['package_paths']['stylesheets']))
		{
			throw new Kohana_Exception('Stylesheets path not set');
		}

		// Save config
		self::$config = $config;

		if (isset($config['filters']))
		{
			foreach ($config['filters'] as $filter)
			{
				$filter = 'Kollapse_Filter_'.$filter;
				// Instantiate filter
				self::$filters[$filter] = new $filter;
			}
		}

		// Instantiate driver
		$driver = 'Kollapse_'.$config['driver'];
		self::$driver = new $driver($config);

	}

	/**
	 * Stores configuration and makes class publicly uninstantiable.
	 *
	 * @param array|null $config
	 */
	protected function __construct(array $config = NULL)
	{
		if ($config === NULL)
		{
			self::__construct($config, FALSE, FALSE);
		}
		else
		{
			self::$config = $config;
		}
	}

	/**
	 * Runs filters on provided data.
	 *
	 * @static
	 * @throws Kohana_Exception
	 * @param  string $data
	 * @param  string $type
	 * @return string
	 */
	protected static function filter($data, $type)
	{
		if ($type !== 'javascripts' AND $type !== 'stylesheets')
		{
			throw new Kohana_Exception("Invalid filter type ':type'",
				array(':type' => $type));
		}

		foreach (self::$filters as $filter)
		{
			if (in_array($type, $filter->filterable))
			{
				$data = $filter->parse($data, $type);
			}
		}

		return $data;
	}

	/**
	 * Creates script package link
	 *
	 * @uses    HTML::script
	 * @param   array|string  group(s) of assets to link
	 * @param   array         additional attributes
	 * @param   boolean       include file timestamp
	 * @return  string
	 */
	public static function scripts($groups, array $attributes = NULL, $timestamp = TRUE)
	{
		if (self::$config === NULL)
		{
			self::init();
		}

		if ( ! is_array($groups))
		{
			$groups = array($groups);
		}

		$packages = '';

		if ( ! self::$config['packaging'])
		{
			foreach ($groups as $group)
			{
				$assets = self::search_wildcard(self::$config['javascripts'][$group]);
				foreach ($assets as $asset)
				{
					$file = pathinfo($asset);
					$path = Kohana::find_file($file['dirname'], $file['filename'], $file['extension']);
					if ($path === FALSE)
					{
						throw new Kohana_Exception('Cannot find file \':basepath\'',
							array(':basepath' => $file['dirname']));
					}
					$asset_timestamp = '';

					if ($timestamp)
					{
						$asset_timestamp = self::timestamp($path);
					}

					$packages .= HTML::script($asset.'?'.$asset_timestamp, $attributes)."\n";
				}
			}
		}
		else
		{
			foreach ($groups as $group)
			{
				$packages .= HTML::script(
					self::package($group, 'javascripts', $timestamp), $attributes
				)."\n";
			}
		}

		return $packages;
	}

	/**
	 * Creates stylesheet package link
	 *
	 * @uses    HTML::style
	 * @param   array|string   asset group(s) to link
	 * @param   array          additional attributes
	 * @param   boolean        include file timestamp
	 * @return  string
	 */
	public static function styles($groups, $attributes = array(), $timestamp = TRUE)
	{
		if (self::$config === NULL)
		{
			self::init();
		}

		if ( ! is_array($groups))
		{
			$groups = array($groups);
		}

		$packages = '';

		if ( ! self::$config['packaging'])
		{
			foreach ($groups as $group)
			{
				$assets = self::search_wildcard(self::$config['stylesheets'][$group]);
				foreach ($assets as $asset)
				{
					$file = pathinfo($asset);
					$path = Kohana::find_file($file['dirname'], $file['filename'], $file['extension']);
					if ($path === FALSE)
					{
						throw new Kohana_Exception('Cannot find file \':basepath\'',
							array(':basepath' => $file['dirname']));
					}

					$asset_timestamp = '';

					if ($timestamp)
					{
						$asset_timestamp = self::timestamp($path);
					}

					$packages .= HTML::style($asset.'?'.$asset_timestamp, $attributes)."\n";
				}
			}
		}
		else
		{
			foreach ($groups as $group)
			{
				$packages .= HTML::style(
					self::package($group, 'stylesheets', $timestamp), $attributes
				)."\n";
			}
		}

		return $packages;
	}

	/**
	 * Filters out wildcards and expose files
	 *
	 * @static
	 * @param  array $group
	 * @return array
	 */
	protected static function search_wildcard(array $group)
	{
		foreach ($group as $index => $asset)
		{
			$file = pathinfo($asset);
			if ($file['filename'] == '*')
			{
				unset($group[$index]);
				$files = Kohana::list_files($file['dirname']);
				foreach ($files as $rel => $abs)
				{
					$group[] = str_replace('\\', '/', $rel);
				}
			}
		}
		return $group;
	}

	/**
	 * Package files
	 * 
	 * @static
	 * @throws Kohana_Exception
	 * @param  $group
	 * @param  $type
	 * @param  bool $timestamp
	 * @return string
	 */
	public static function package($group, $type, $timestamp = TRUE)
	{
		if ( ! isset(self::$config[$type][$group]))
		{
			throw new Kohana_Exception('Asset group \':group\' does not exist',
				array(':group' => $group));
		}

		$assets = self::$config[$type][$group];

		switch ($type)
		{
			case 'javascripts':
				$extension = '.js';
			break;
			case 'stylesheets':
				$extension = '.css';
			break;
			default:
				throw new Kohana_Exception('Invalid asset type \':type\'',
					array(':type' => $type));
		}

		$package = self::$config['package_paths'][$type].$group;
		$package_url = substr_replace($package, '', 0, strlen(DOCROOT)).$extension;
		$extension = (self::$config['gzip_compression']) ? $extension.'.gz' : $extension;
		$package .= $extension;

		if ( ! file_exists($package) OR (is_file($package) AND self::package_outdated($package, $assets)))
		{
			self::build_package($package, $assets, $type);
		}

		return ($timestamp) ? $package_url.'?'.self::timestamp($package) : $package_url;
	}

	/**
	 * Rebuild package
	 *
	 * @static
	 * @throws Kohana_Exception
	 * @param  string $package
	 * @param  array  $assets
	 * @param  string $type
	 * @return void
	 */
	public static function build_package($package, array $assets, $type)
	{
		if ( ! file_exists($package))
		{
			if ( ! is_writable(dirname($package)))
			{
				throw new Kohana_Exception(":asset directory ':directory' must be writable",
					array(':asset' => ucfirst($type), ':directory' => dirname($package)));
			}
		}
		elseif ( ! is_writable($package))
		{
			throw new Kohana_Exception(":asset package ':package' must be writable",
				array(':asset' => ucfirst($type), ':package' => $package));
		}

		$data = '';
		$assets = self::search_wildcard($assets);
		foreach ($assets as $asset)
		{
			$file = pathinfo($asset);
			$path = Kohana::find_file($file['dirname'], $file['filename'], $file['extension']);

			if ( ! is_file($path))
			{
				throw new Kohana_Exception(":type asset ':file' does not exist",
					array(':type' => ucfirst($type), ':file' => $asset));
			}

			$data .= file_get_contents($path)."\n";
		}

		$data = self::filter($data, $type);

		$data = self::$driver->optimize($data, $package, $type);

		if (self::$config['gzip_compression'])
		{
			$data = gzencode($data);
		}

		file_put_contents($package, $data);
	}

	/**
	 * Abstract optimize for drivers
	 *
	 * @abstract
	 * @param  $data
	 * @param  $package
	 * @param  $type
	 * @return void
	 */
	abstract protected function optimize($data, $package, $type);

	/**
	 * Check whether the specified package is outdated
	 *
	 * @static
	 * @param  string $package
	 * @param  array  $assets
	 * @return bool
	 */
	public static function package_outdated($package, $assets)
	{
		$outdated = FALSE;
		$latest = 0;

		$assets = self::search_wildcard($assets);
		foreach ($assets as $asset)
		{
			$file = pathinfo($asset);
			$path = Kohana::find_file($file['dirname'], $file['filename'], $file['extension']);
			if ($path === FALSE)
			{
				throw new Kohana_Exception('Cannot find file \':basepath\'',
					array(':basepath' => $file['dirname']));
			}

			$timestamp = self::timestamp($path);

			if ($timestamp > $latest)
			{
				// current asset is newest
				$latest = $timestamp;
			}
		}

		if ($latest > self::timestamp($package))
		{
			// package is outdated
			$outdated = TRUE;
		}

		return $outdated;
	}

	/**
	 * Get the last modified timestamp for a file
	 *
	 * @static
	 * @throws Kohana_Exception
	 * @param  string  $file  File path
	 * @return int
	 */
	protected static function timestamp($file)
	{
		if ( ! isset(self::$timestamps[$file]))
		{
			if ( ! file_exists($file))
			{
				throw new Kohana_Exception("Asset ':file' does not exist",
					array(':file' => $file));
			}

			// get & save timestamp
			self::$timestamps[$file] = filemtime($file);
		}

		return self::$timestamps[$file];
	}

} // End Kohana_Kollapse