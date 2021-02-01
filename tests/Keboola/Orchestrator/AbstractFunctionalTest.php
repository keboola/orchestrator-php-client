<?php
namespace Keboola\Tests\Orchestrator;

use GuzzleHttp\Client;
use Keboola\Orchestrator\Client AS OrchestratorApi;

use Keboola\StorageApi\Client AS StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;

abstract class AbstractFunctionalTest extends \PHPUnit_Framework_TestCase
{
	const TESTING_ORCHESTRATION_NAME = 'PHP Client test';
	const TESTING_EXTRACTOR_NAME = 'Orchestrator PHP Client test';

	const TESTING_COMPONENT_ID = 'keboola.ex-db-snowflake';

	/** @var OrchestratorApi */
	protected $client;

	/** @var StorageApi */
	protected $sapiClient;

	protected $testComponentConfiguration;

	protected $testComponentConfigurationId;

	protected $testProjectId;

	public function setUp()
	{
		$this->client = OrchestratorApi::factory(array(
			'url' => FUNCTIONAL_ORCHESTRATOR_API_URL,
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN
		));

		$this->sapiClient = new StorageApi(array(
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN,
		));

		$token = $this->sapiClient->verifyToken();
		$this->testProjectId = $token['owner']['id'];

		// clean old tests
		$this->cleanWorkspace();

		$this->testComponentConfiguration = $this->createTestExtractor();
		$this->testComponentConfigurationId = $this->testComponentConfiguration['id'];
	}

	private function cleanWorkspace()
	{
		$orchestrations = $this->client->getOrchestrations();

		foreach ($orchestrations AS $orchestration) {
			if (strpos($orchestration['name'], self::TESTING_ORCHESTRATION_NAME) === false)
				continue;

			$this->client->deleteOrchestration($orchestration['id']);
		}

		// clean old configurations
		$components = new Components($this->sapiClient);

		$listOptions = new ListComponentConfigurationsOptions();
		$listOptions->setComponentId(self::TESTING_COMPONENT_ID);

		foreach ($components->listComponentConfigurations($listOptions) as $configuration) {
			if ($configuration['name'] === self::TESTING_EXTRACTOR_NAME) {
				$components->deleteConfiguration(self::TESTING_COMPONENT_ID, $configuration['id']);
			}
		}
	}

	protected function createTestExtractor()
	{
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

		$components = new Components($this->sapiClient);

		$configuration = new Configuration();
		$configuration->setComponentId(self::TESTING_COMPONENT_ID);
		$configuration->setName(self::TESTING_EXTRACTOR_NAME);
		$configuration->setDescription('used in orchestrator tests');
		$configuration->setConfiguration(['parameters' => $parameters]);

		$result = $components->addConfiguration($configuration);

		// create workspace
		$workspace = $this->sapiClient->apiPost(sprintf(
			'storage/components/%s/configs/%s/workspaces',
			self::TESTING_COMPONENT_ID,
			$result['id']
		));

		// encrypt password
		$guzzle = new Client();

		$response = $guzzle->post(
			sprintf(
				'https://docker-runner.keboola.com/docker/encrypt?componentId=%s&projectId=%s',
				self::TESTING_COMPONENT_ID,
				$this->testProjectId
			),
			[
				'body' => $workspace['connection']['password'],
				'headers' => [
					'Content-Type' => 'text/plain',
				]
			]
		);

		// update extractor credentials
		$parameters['db'] = [
			'host' => $workspace['connection']['host'],
			'port' => 443,
			'database' => $workspace['connection']['database'],
			'schema' => $workspace['connection']['schema'],
			'warehouse' => $workspace['connection']['warehouse'],
			'user' => $workspace['connection']['user'],
			'#password' => $response->getBody()->getContents(),
		];

		$configuration->setConfiguration(['parameters' => $parameters]);
		$configuration->setConfigurationId($result['id']);

		$result = $components->updateConfiguration($configuration);

		return $result;
	}

	protected function waitForJobFinish($jobId)
	{
		$retries = 0;
		$job = null;

		// poll for status
		do {
			if ($retries > 0) {
				sleep(min(pow(2, $retries), 20));
			}
			$retries++;
			$job = $this->client->getJob($jobId);
			$jobId = $job['id'];
		} while (!$job['isFinished']);

		return $job;
	}

	protected function waitForJobStart($jobId)
	{
		$retries = 0;
		$job = null;

		// poll for status
		do {
			if ($retries > 0) {
				sleep(min(pow(2, $retries), 20));
			}
			$retries++;
			$job = $this->client->getJob($jobId);
			$jobId = $job['id'];
		} while ($job['status'] === 'waiting');

		return $job;
	}
}
