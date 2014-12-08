<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Helper;

use b8\Config;

/**
 * Languages Helper Class - Handles loading strings files and the strings within them.
 * @package PHPCI\Helper
 */
class Lang
{
    protected static $language = null;
    protected static $strings = array();

    /**
     * Get a specific string from the language file.
     * @param $string
     * @return mixed|string
     */
    public static function get($string)
    {
        $vars = func_get_args();

        if (array_key_exists($string, self::$strings)) {
            $vars[0] = self::$strings[$string];
            return call_user_func_array('sprintf', $vars);
        }

        return '%%MISSING STRING: ' . $string . '%%';
    }

    /**
     * Output a specific string from the language file.
     */
    public static function out()
    {
        print call_user_func_array(array('PHPCI\Helper\Lang', 'get'), func_get_args());
    }

    /**
     * Get the currently active language.
     * @return string|null
     */
    public static function getLanguage()
    {
        return self::$language;
    }

    /**
     * Get the strings for the currently active language.
     * @return string[]
     */
    public static function getStrings()
    {
        return self::$strings;
    }

    /**
     * Initialise the Language helper, try load the language file for the user's browser or the configured default.
     * @param Config $config
     */
    public static function init(Config $config)
    {
        // Try user language:
        if (isset($_SERVER) && array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER)) {
            $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);

            foreach ($langs as $lang) {
                $parts = explode(';', $lang);

                self::$language = strtolower($parts[0]);
                self::$strings = self::loadLanguage();

                if (!is_null(self::$strings)) {
                    return;
                }
            }
        }

        // Try the installation default language:
        self::$language = $config->get('phpci.default_language', null);

        if (!is_null(self::$language)) {
            self::$strings = self::loadLanguage();

            if (!is_null(self::$strings)) {
                return;
            }
        }

        // Fall back to en-GB:
        self::$language = 'en';
        self::$strings = self::loadLanguage();
    }

    /**
     * Load a specific language file.
     * @return string[]|null
     */
    protected static function loadLanguage()
    {
        $langFile = PHPCI_DIR . 'PHPCI/Languages/lang.' . self::$language . '.php';

        if (!file_exists($langFile)) {
            return null;
        }

        require_once($langFile);

        if (is_null($strings) || !is_array($strings) || !count($strings)) {
            return null;
        }

        return $strings;
    }
}
