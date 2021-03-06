<?php

/**
 * Provides a simple image cache, intended for serving http images over https.
 *
 * For proper auto detection, this file must be located in SUBSDIR and
 * follow naming conventions XxxYYY.integrate => Xxx_Yyy_Integrate
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2017 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.0
 *
 */

/**
 * Class Image_Cache_Integrate
 *
 * - For proper auto detection, this file must be located in SUBSDIR and named xxx.integrate
 * - The class must be xxx_Integrate
 * - collection of static methods
 */
class Image_Cache_Integrate
{
	static public $js_load = false;

	/**
	 * Register ImageCache hooks to the system
	 *
	 * - register method is called statically from loadIntegrations in Hooks.class
	 * - used here to update bbc img tag rendering
	 *
	 * @return array
	 */
	public static function register()
	{
		global $modSettings;

		if (empty($modSettings['image_cache_enabled']))
		{
			return array();
		}

		// $hook, $function, $file
		return array(
			array('integrate_additional_bbc', 'Image_Cache_Integrate::integrate_additional_bbc'),
		);
	}

	/**
	 * Register ACP hooks for setting values in various areas
	 *
	 * - settingsRegister method is called statically from loadIntegrationsSettings in Hooks.class
	 * - used here to add ACP maintenance functions for the image cache
	 *
	 * @return array
	 */
	public static function settingsRegister()
	{
		// $hook, $function, $file
		return array(
			// Clear image cache in routine maintenance
			array('integrate_routine_maintenance', 'ManageImageCacheModule_Controller::ic_integrate_routine_maintenance'),
			// Clear image cache in scheduled tasks
			array('integrate_sa_manage_maintenance', 'ManageImageCacheModule_Controller::ic_integrate_sa_manage_maintenance'),
		);
	}

	/**
	 * Determines if the image would require cache usage
	 *
	 * - Used by the updated BBC img codes added by integrate_additional_bbc
	 *
	 * @return Closure
	 */
	public static function imageNeedsCache()
	{
		global $boardurl, $txt, $modSettings;

		// Trickery for 5.3
		$js_loaded =& self::$js_load;
		$always = !empty($modSettings['image_cache_all']);

		// Return a closure function for the bbc code
		return function (&$tag, &$data, $disabled) use ($boardurl, $txt, &$js_loaded, $always)
		{
			$data = addProtocol($data);

			$parseBoard = parse_url($boardurl);
			$parseImg = parse_url($data);

			// No need to cache an image that is not going over https, or is already https over https
			if (!$always && ($parseBoard['scheme'] === 'http' || $parseBoard['scheme'] === $parseImg['scheme']))
			{
				return false;
			}

			// Flag the loading of js
			if ($js_loaded === false)
			{
				$js_loaded = true;
				loadJavascriptFile('imagecache.js', array('defer' => true));
			}

			// Use the image cache to check availability
			$proxy = new Image_Cache(database(), $data);
			$cache_hit = $proxy->getImageFromCacheTable();

			// A false or numeric result means we need to try
			if ($cache_hit === true)
			{
				$proxy->updateImageCacheHitDate();
			}
			else
			{
				// A false result means we never tried to get this file
				if ($cache_hit === false)
				{
					$proxy->createCacheImage();
				}
				// A numeric means we have tried and failed
				else
				{
					$proxy->retryCreateImageCache();
				}
			}

			$data = $boardurl . '/imagecache.php?image=' . urlencode($data) . '&hash=' . $proxy->getImageCacheHash() . '" rel="cached" data-warn="' . Util::htmlspecialchars($txt['image_cache_warn_ext']) . '" data-url="' . Util::htmlspecialchars($data);

			return true;
		};
	}

	/**
	 * $codes will be populated with what other addons, modules etc have added to the system
	 * but will not contain the default codes. Codes added here will parse before any default ones,
	 * effectively over writing them as default codes are appended to this this array.
	 *
	 * @param array $codes
	 */
	public static function integrate_additional_bbc(&$codes)
	{
		loadCSSFile('imagecache.css');
		loadLanguage('ImageCache');

		// Add Image Cache codes
		$codes = array_merge($codes, array(
			array(
				\BBC\Codes::ATTR_TAG => 'img',
				\BBC\Codes::ATTR_TYPE => \BBC\Codes::TYPE_UNPARSED_CONTENT,
				\BBC\Codes::ATTR_PARAM => array(
					'width' => array(
						\BBC\Codes::PARAM_ATTR_VALUE => 'width:100%;max-width:$1px;',
						\BBC\Codes::PARAM_ATTR_MATCH => '(\d+)',
						\BBC\Codes::PARAM_ATTR_OPTIONAL => true,
					),
					'height' => array(
						\BBC\Codes::PARAM_ATTR_VALUE => 'max-height:$1px;',
						\BBC\Codes::PARAM_ATTR_MATCH => '(\d+)',
						\BBC\Codes::PARAM_ATTR_OPTIONAL => true,
					),
					'title' => array(
						\BBC\Codes::PARAM_ATTR_MATCH => '(.+?)',
						\BBC\Codes::PARAM_ATTR_OPTIONAL => true,
					),
					'alt' => array(
						\BBC\Codes::PARAM_ATTR_MATCH => '(.+?)',
						\BBC\Codes::PARAM_ATTR_OPTIONAL => true,
					),
				),
				\BBC\Codes::ATTR_CONTENT => '<img src="$1" alt="{alt}" style="{width}{height}" class="bbc_img resized" />',
				\BBC\Codes::ATTR_VALIDATE => self::imageNeedsCache(),
				\BBC\Codes::ATTR_DISABLED_CONTENT => '($1)',
				\BBC\Codes::ATTR_BLOCK_LEVEL => false,
				\BBC\Codes::ATTR_AUTOLINK => false,
				\BBC\Codes::ATTR_LENGTH => 3,
			),
			array(
				\BBC\Codes::ATTR_TAG => 'img',
				\BBC\Codes::ATTR_TYPE => \BBC\Codes::TYPE_UNPARSED_CONTENT,
				\BBC\Codes::ATTR_CONTENT => '<img src="$1" alt="" class="bbc_img" />',
				\BBC\Codes::ATTR_VALIDATE => self::imageNeedsCache(),
				\BBC\Codes::ATTR_DISABLED_CONTENT => '($1)',
				\BBC\Codes::ATTR_BLOCK_LEVEL => false,
				\BBC\Codes::ATTR_AUTOLINK => false,
				\BBC\Codes::ATTR_LENGTH => 3,
			)
		));
	}
}
