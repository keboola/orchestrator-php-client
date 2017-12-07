<?php
namespace Keboola\Orchestrator\Tests;

use Keboola\Orchestrator\Client AS OrchestratorApi;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client AS StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use PHPUnit\Framework\TestCase;

class TokenPermissionsTest extends TestCase
{
	/**
	 * @var OrchestratorApi
	 */
	private static $client;

	/**
	 * @var StorageApi
	 */
	private static $sapiClient;

	const TESTING_ORCHESTRATION_NAME = 'PHP Client test';

	const TESTING_COMPONENT_ID = 'keboola.ex-db-snowflake';

	private static $testComponentConfigId = null;

	private static $testManageTokenId = null;

	public static function setUpBeforeClass()
	{
		self::$sapiClient = new StorageApi(array(
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN,
			'url' => defined('FUNCTIONAL_SAPI_URL') ? FUNCTIONAL_SAPI_URL : null
		));

		self::$sapiClient->verifyToken();

		$token = self::createNewManageToken(self::$sapiClient);

		self::$testManageTokenId = $token['id'];

		self::$client = OrchestratorApi::factory(array(
			'url' => FUNCTIONAL_ORCHESTRATOR_API_URL,
			'token' => $token['token'],
		));

		// cleanup configurations
		$components = new Components(self::$sapiClient);

		$listOptions = new ListComponentConfigurationsOptions();
		$listOptions->setComponentId(self::TESTING_COMPONENT_ID);

		foreach ($components->listComponentConfigurations($listOptions) as $configuration) {
			if ($configuration['name'] === self::TESTING_ORCHESTRATION_NAME) {
				$components->deleteConfiguration(self::TESTING_COMPONENT_ID, $configuration['id']);
			}
		}

		self::$testComponentConfigId = self::createTestExtractor();
	}

	public function setUp()
	{
		$orchestrations = self::$client->getOrchestrations();

		foreach ($orchestrations AS $orchestration) {
			if (strpos($orchestration['name'], self::TESTING_ORCHESTRATION_NAME) === false)
				continue;

			self::$client->deleteOrchestration($orchestration['id']);
		}
	}

	private static function createNewManageToken(StorageApi $storageApi)
	{
		$tokenId = $storageApi->createToken(
			'manage',
			sprintf('Orchestrator %s', self::TESTING_ORCHESTRATION_NAME),
			3600 * 2,
			true
		);

		$token = $storageApi->getToken($tokenId);
		return $token;
	}

	private static function createTestExtractor()
	{
		// create configuration
		$components = new Components(self::$sapiClient);

		$parameters = [
			'tables' => [
				[
					'id' => 1,
					'outputTable' => 'in.c-php-orchestrator-tests.time',
					'name' => 'Test Query',
					'query' => 'SELECT CURRENT_DATE() AS "sample"',
					'enabled' => true,
				]
			]
		];

		$configuration = new Configuration();
		$configuration->setComponentId(self::TESTING_COMPONENT_ID);
		$configuration->setName(self::TESTING_ORCHESTRATION_NAME);
		$configuration->setDescription('used in orchestrator functional tests');
		$configuration->setConfiguration(['parameters' => $parameters]);

		$result = $components->addConfiguration($configuration);

		// create workspace
		$workspace = $components->createConfigurationWorkspace(self::TESTING_COMPONENT_ID, $result['id']);

		// update configuration
		$parameters['db'] = [
			'host' => $workspace['connection']['host'],
			'port' => null,
			'database' => $workspace['connection']['database'],
			'schema' => $workspace['connection']['schema'],
			'warehouse' => $workspace['connection']['warehouse'],
			'user' => $workspace['connection']['user'],
			'password' => $workspace['connection']['password'],
		];

		$configuration->setConfiguration(['parameters' => $parameters]);
		$configuration->setConfigurationId($result['id']);

		$components->updateConfiguration($configuration);

		return $result['id'];
	}

	/**
	 * @return OrchestrationTask[]
	 */
	private function createTestData()
	{
		$tasks = array(
			(new OrchestrationTask())
				->setComponent(self::TESTING_COMPONENT_ID)
				->setAction('run')
				->setActionParameters([
					'config' => self::$testComponentConfigId,
				])
		);

		return $tasks;
	}

	public function testOrchestrations()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
			'tokenId' => self::$testManageTokenId,
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration);
		$this->assertArrayHasKey('crontabRecord', $orchestration);
		$this->assertArrayHasKey('nextScheduledTime', $orchestration);

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestData());

		// enqueue job
		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}
		// job detail
		$job = self::$client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);
		$this->assertEquals('success', $job['status']);

		// delete orchestration
		$result = self::$client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result);
	}
}
