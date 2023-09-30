# Keboola Orchestrator API PHP client

[![Branch workflow](https://github.com/keboola/orchestrator-php-client/actions/workflows/branch.yml/badge.svg?branch=master)](https://github.com/keboola/orchestrator-php-client/actions/workflows/branch.yml)

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
            "php" : ">=5.6",
            "keboola/orchestrator-php-client": "1.3.*"
        }
    }

**Install package:**

    composer install


**Add autoloader in your bootstrap script:**

    require 'vendor/autoload.php';


Read more in [Composer documentation](http://getcomposer.org/doc/01-basic-usage.md)

## Usage
Execute all orchestrations in KBC project example:

```php
use Keboola\Orchestrator\Client;

$client = Client::factory(array(
    'token' => 'YOUR_TOKEN',
));

// retrieve all orchestrations in KBC project
$orchestrations = $client->getOrchestrations();

foreach ($orchestrations AS $orchestration) {
    // manually execute orchestration
    $client->createJob($orchestration['id']);
}
```

## Tests

To run tests you need **Storage API token** to an **empty project** in Keboola Connection. The project must be in **US region**.

Create `.env` file with environment variables:

```bash
ORCHESTRATOR_API_URL=https://syrup.keboola.com/orchestrator/
ORCHESTRATOR_API_TOKEN=your_token
ERROR_NOTIFICATION_EMAIL={your_email}
```
 
- `ORCHESTRATOR_API_URL` - Url of Orchestrator Rest API endpoint
- `ORCHESTRATOR_API_TOKEN` - Valid Storage API token. Token must have `canManageTokens` permissions.
- `ERROR_NOTIFICATION_EMAIL` - Your email address. It will be used in orchestrator notification settings.

Build image and run tests

```bash
docker network create orchestrator-router_api-tests
docker-compose build tests
docker-compose run --rm tests ./vendor/bin/phpunit
``` 

## License

MIT licensed, see [LICENSE](./LICENSE) file.
