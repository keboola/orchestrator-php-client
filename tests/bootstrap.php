<?php
error_reporting(E_ALL);
date_default_timezone_set('Europe/Prague');

define('FUNCTIONAL_ORCHESTRATOR_API_URL', getenv('ORCHESTRATOR_API_URL'));
define('FUNCTIONAL_ORCHESTRATOR_API_TOKEN', getenv('ORCHESTRATOR_API_TOKEN'));
define('FUNCTIONAL_ERROR_NOTIFICATION_EMAIL', getenv('ERROR_NOTIFICATION_EMAIL'));

require __DIR__ . '/../vendor/autoload.php';
