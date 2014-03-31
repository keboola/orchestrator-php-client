# Keboola Orchestrator API PHP client

Simple PHP wrapper library for [Keboola Orchestrator REST API](http://docs.keboolaorchestratorv2api.apiary.io/)

## Installation

Library is available as composer package.
To start using composer in your project follow these steps:

**Install composer**
  
    curl -s http://getcomposer.org/installer | php
    mv ./composer.phar ~/bin/composer # or /usr/local/bin/composer


**Create composer.json file in your project root folder:**

    {
        "require": {
            "php" : ">=5.3.2",
            "keboola/orchestrator-php-client": "1.0.*"
        }
    }

**Install package:**

    composer install


**Add autoloader in your bootstrap script:**

    require 'vendor/autoload.php';


Read more in [Composer documentation](http://getcomposer.org/doc/01-basic-usage.md)



## Tests
Tests requires valid Storage API token and URL of API.
You can set these by copying file config.template.php into config.php and filling required constants int config.php file. Other way to provide parameters is to set environment variables:

**Never run this tests on production user with real data, always create user for testing purposes!!!**

When the parameters are set you can run tests by **phpunit** command. 

