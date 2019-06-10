<?php
// Define path to application directory
if (!defined('ROOT_PATH'))
	define('ROOT_PATH', __DIR__);

ini_set('display_errors', true);

date_default_timezone_set('Europe/Prague');

if (file_exists(ROOT_PATH . '/tests/config/config.php')) {
	require_once ROOT_PATH . '/tests/config/config.php';
}

require_once ROOT_PATH . '/vendor/autoload.php';