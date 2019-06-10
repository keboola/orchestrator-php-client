<?php
error_reporting(E_ALL);
date_default_timezone_set('Europe/Prague');

define('FUNCTIONAL_ORCHESTRATOR_API_URL', getenv('ORCHESTRATOR_API_URL'));
define('FUNCTIONAL_ORCHESTRATOR_API_TOKEN', getenv('ORCHESTRATOR_API_TOKEN'));
define('FUNCTIONAL_ERROR_NOTIFICATION_EMAIL', getenv('STORAGE_API_LINKING_TOKEN'));

require __DIR__ . '/../vendor/autoload.php';
