<?php
namespace Keboola\Orchestrator\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Command\Exception\CommandClientException;;
use Keboola\Orchestrator\Client AS OrchestratorApi;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client AS StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use PHPUnit\Framework\TestCase;

class FunctionalTest extends TestCase
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

	public static function setUpBeforeClass()
	{
		self::$client = OrchestratorApi::factory(array(
			'url' => FUNCTIONAL_ORCHESTRATOR_API_URL,
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN
		));

		self::$sapiClient = new StorageApi(array(
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN,
			'url' => defined('FUNCTIONAL_SAPI_URL') ? FUNCTIONAL_SAPI_URL : null
		));

		self::$sapiClient->verifyToken();

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

		// encrypt password
		$tokendData = self::$sapiClient->verifyToken();
		$guzzle = new Client();

		$response = $guzzle->post(
			sprintf(
				'https://syrup.keboola.com/docker/encrypt?componentId=%s&projectId=%s',
				self::TESTING_COMPONENT_ID,
				$tokendData['owner']['id']
			),
			[
				'body' => $workspace['connection']['password'],
				'headers' => [
					'Content-Type' => 'text/plain',
				]
			]
		);

		// update configuration
		$parameters['db'] = [
			'host' => $workspace['connection']['host'],
			'port' => null,
			'database' => $workspace['connection']['database'],
			'schema' => $workspace['connection']['schema'],
			'warehouse' => $workspace['connection']['warehouse'],
			'user' => $workspace['connection']['user'],
			'#password' => $response->getBody()->getContents(),
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

	/**
	 * @return OrchestrationTask[]
	 */
	private function createTestDataWithError()
	{
		$tasks = array();

		array_push($tasks, (new OrchestrationTask())
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('test')
			->setActionParameters([
				'config' => self::$testComponentConfigId,
			])
		);

		array_push($tasks, (new OrchestrationTask())
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters([
				'config' => self::$testComponentConfigId,
			])
		);

		return $tasks;
	}

	/**
	 * @return OrchestrationTask[]
	 */
	private function createTestDataWithWarn()
	{
		$tasks = array();

		array_push($tasks, (new OrchestrationTask())
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('test')
			->setContinueOnFailure(true)
			->setActionParameters([
				'config' => self::$testComponentConfigId,
			])
		);

		array_push($tasks, (new OrchestrationTask())
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters([
				'config' => self::$testComponentConfigId,
			])
		);

		return $tasks;
	}

	public function testOrchestrationTasks()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration);
		$this->assertArrayHasKey('crontabRecord', $orchestration);
		$this->assertArrayHasKey('nextScheduledTime', $orchestration);

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestData());

		$this->assertCount(count($this->createTestData()), $tasks);

		// modify tasks
		$orchestrationTasks = $this->createTestDataWithError();
		$tasks = self::$client->updateTasks($orchestration['id'], $orchestrationTasks);

		$this->assertCount(count($orchestrationTasks), $tasks);
		$this->assertTrue(count($orchestrationTasks) > count($this->createTestData()));

		$task = $tasks[0];

		$this->assertEquals(true, $task['active']);
		$this->assertEquals(false, $task['continueOnFailure']);
		$this->assertEquals($orchestrationTasks[0]->getComponent(), $task['component']);

		// erase all tasks
		$count = 0;
		$tasks = self::$client->updateTasks($orchestration['id'], array());

		$this->assertCount($count, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", $count));

		// delete orchestration
		$result = self::$client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
	}

	public function testOrchestrations()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestData());

		// orchestration detail
		$orchestration = self::$client->getOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'getOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");

		// orchestration update
		$crontabRecord = '* * * * *';
		$active = false;

		$options = array(
			'active' => $active,
			'crontabRecord' => $crontabRecord,
			'tasks' => array_map(
				function (OrchestrationTask $task) {
					return $task->toArray();
				},
				$this->createTestData()
			),
		);

		$orchestration = self::$client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$job = self::$client->runOrchestration($orchestration['id']);

		// list of orchestration jobs
		sleep(2);
		$jobs = self::$client->getOrchestrationJobs($orchestration['id']);

		$this->assertCount(2, $jobs, "Result of API command 'getOrchestrationJobs' should return 2 jobs");

		$this->assertArrayHasKey('id', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('orchestrationId', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('status', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertEquals($orchestration['id'], $jobs[0]['orchestrationId'], "Result of API command 'getOrchestrationJobs' should return jobs for given orchestration");

		// job detail
		$job = self::$client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		// wait for job processing
		while (in_array($job['status'], array('waiting'))) {
			sleep(3);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$processingJob = $job;

		// job cancel
		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		$result = self::$client->cancelJob($job['id']);
		$this->assertTrue($result, "Result of API command 'cancelJob' should return TRUE");

		sleep(10);
		$job = self::$client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		$allowedStatus = array(
			'cancelled',
			'terminated',
			'terminating'
		);

		$this->assertTrue(in_array($job['status'], $allowedStatus) , "Result of API command 'getJob' should return cancelled or terminated job job");


		// job stats

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$errorsCount = 0;
		$cancelledCount = 0;
		$successCount = 0;
		$processingCount = 0;
		$warnCount = 0;
		$otherCount = 0;

		sleep(2);
		$jobs = self::$client->getOrchestrationJobs($orchestration['id']);

		foreach ($jobs AS $job) {
			if ($job['status'] === 'success') {
				$successCount++;
				continue;
			}

			if ($job['status'] === 'processing') {
				$processingCount++;
				continue;
			}

			if ($job['status'] === 'warning') {
				$warnCount++;
				continue;
			}

			if ($job['status'] === 'error') {
				$errorsCount++;
				continue;
			}

			if ($job['status'] === 'cancelled') {
				$cancelledCount++;
				continue;
			}

			if ($job['status'] === 'terminated') {
				$cancelledCount++;
				continue;
			}

			if ($job['status'] === 'terminating') {
				$cancelledCount++;
				continue;
			}

			$otherCount++;
		}

		$this->assertLessThan(1, $errorsCount, "Result of API command 'getOrchestrationJobs' should return any job with 'error' status");
		$this->assertLessThan(1, $warnCount, "Result of API command 'getOrchestrationJobs' should return any job with 'warning' status");
		$this->assertGreaterThan(0, $successCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'success' status");
		$this->assertLessThan(1, $otherCount, "Result of API command 'getOrchestrationJobs' should return only finished jobs");

		// delete orchestration
		$result = self::$client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
	}

	public function testOrchestrationsCreateWithTasksAndNotifications()
	{
		// create orchestration
		$testTasks = $this->createTestData();
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
			'tasks' => array_map(
				function(OrchestrationTask $task) {
					return $task->toArray();
				},
				$testTasks
			),
			'notifications' => array(
				0 => array(
					'channel' => 'error',
					'email' => 'devel@keboola.com',
				)

			)
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$this->assertCount(1, $orchestration['tasks']);
		$task = reset($orchestration['tasks']);

		$this->assertArrayHasKey('id', $task);
		$this->assertArrayHasKey('component', $task);
		$this->assertArrayHasKey('active', $task);
		$this->assertArrayHasKey('actionParameters', $task);

		$this->assertNotEmpty('id', $task['id']);
		$this->assertEquals($testTasks[0]->getComponent(), $task['component']);
		$this->assertEquals($testTasks[0]->getActionParameters(), $task['actionParameters']);
		$this->assertTrue($task['active']);

		$this->assertCount(1, $orchestration['notifications']);
		$notification = reset($orchestration['notifications']);

		$this->assertArrayHasKey('email', $notification);
		$this->assertArrayHasKey('channel', $notification);
		$this->assertEquals('devel@keboola.com', $notification['email']);
		$this->assertEquals('error', $notification['channel']);

		// orchestration detail
		$orchestration = self::$client->getOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'getOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");

		$this->assertCount(1, $orchestration['tasks']);
		$task = reset($orchestration['tasks']);

		$this->assertArrayHasKey('id', $task);
		$this->assertArrayHasKey('component', $task);
		$this->assertArrayHasKey('active', $task);
		$this->assertArrayHasKey('actionParameters', $task);

		$this->assertNotEmpty('id', $task['id']);
		$this->assertEquals($testTasks[0]->getComponent(), $task['component']);
		$this->assertEquals($testTasks[0]->getActionParameters(), $task['actionParameters']);
		$this->assertTrue($task['active']);

		$this->assertCount(1, $orchestration['notifications']);
		$notification = reset($orchestration['notifications']);

		$this->assertArrayHasKey('email', $notification);
		$this->assertArrayHasKey('channel', $notification);
		$this->assertEquals('devel@keboola.com', $notification['email']);
		$this->assertEquals('error', $notification['channel']);
	}

	public function testOrchestrationsError()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestDataWithError());

		// orchestration detail
		$orchestration = self::$client->getOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'getOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");

		// orchestration update
		$crontabRecord = '* * * * *';
		$active = false;

		$options = array(
			'active' => $active,
			'crontabRecord' => $crontabRecord,
		);

		$orchestration = self::$client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$this->assertArrayHasKey('status', $job);
		$job = self::$client->runOrchestration($orchestration['id']);

		// list of orchestration jobs
		sleep(2);
		$jobs = self::$client->getOrchestrationJobs($orchestration['id']);
		$this->assertCount(2, $jobs, "Result of API command 'getOrchestrationJobs' should return 2 jobs");

		$this->assertArrayHasKey('id', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('orchestrationId', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('status', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertEquals($orchestration['id'], $jobs[0]['orchestrationId'], "Result of API command 'getOrchestrationJobs' should return jobs for given orchestration");

		// job detail
		$job = self::$client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		// wait for job processing
		while (in_array($job['status'], array('waiting'))) {
			sleep(3);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
			$this->assertArrayHasKey('isFinished', $job, "Result of API command 'getJob' should contain isFinished status");
		}

		$processingJob = $job;

		// job stats

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$errorsCount = 0;
		$cancelledCount = 0;
		$successCount = 0;
		$processingCount = 0;
		$warnCount = 0;
		$otherCount = 0;

		sleep(2);
		$jobs = self::$client->getOrchestrationJobs($orchestration['id']);
		foreach ($jobs AS $job) {
			if ($job['status'] === 'success') {
				$successCount++;
				continue;
			}

			if ($job['status'] === 'processing') {
				$processingCount++;
				continue;
			}

			if ($job['status'] === 'warning') {
				$warnCount++;
				continue;
			}

			if ($job['status'] === 'error') {
				$errorsCount++;
				continue;
			}

			if ($job['status'] === 'cancelled') {
				$cancelledCount++;
				continue;
			}

			if ($job['status'] === 'terminated') {
				$cancelledCount++;
				continue;
			}

			if ($job['status'] === 'terminating') {
				$cancelledCount++;
				continue;
			}

			$otherCount++;
		}

		$this->assertLessThan(1, $successCount, "Result of API command 'getOrchestrationJobs' should return any job with 'status' status");
		$this->assertLessThan(1, $warnCount, "Result of API command 'getOrchestrationJobs' should return any job with 'warning' status");
		$this->assertGreaterThan(0, $errorsCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'error' status");
		$this->assertLessThan(1, $otherCount, "Result of API command 'getOrchestrationJobs' should return only finished jobs");

		// delete orchestration
		$result = self::$client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
	}

	public function testOrchestrationsWarnInResult()
	{
		// create orchestrations
		$masterOrchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $masterOrchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $masterOrchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $masterOrchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$childOrchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $childOrchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $childOrchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $childOrchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// setup tasks
		self::$client->updateTasks($childOrchestration['id'], $this->createTestDataWithWarn());

		$task = new OrchestrationTask();
		$task->setComponentUrl(FUNCTIONAL_ORCHESTRATOR_API_URL . 'run')->setActionParameters(['config' => $childOrchestration['id']]);

		self::$client->updateTasks($masterOrchestration['id'], [$task]);

		$job = self::$client->runOrchestration($masterOrchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$this->assertArrayHasKey('status', $job);
		$this->assertEquals('error', $job['status']);

		$this->assertArrayHasKey('results', $job);
		$this->assertArrayHasKey('tasks', $job['results']);

		$this->assertEquals(1, count($job['results']['tasks']));

		foreach ($job['results']['tasks'] as $task) {
			$this->assertArrayHasKey('status', $task);
			$this->assertArrayHasKey('response', $task);

			$this->assertArrayHasKey('status', $task['response']);
			$this->assertArrayHasKey('isFinished', $task['response']);

			$this->assertTrue($task['response']['isFinished']);
			$this->assertEquals($task['response']['status'], $task['status']);
		}
	}

	public function testOrchestrationsWarn()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestDataWithWarn());

		// orchestration detail
		$orchestration = self::$client->getOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'getOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");

		// orchestration update
		$crontabRecord = '* * * * *';
		$active = false;

		$options = array(
			'active' => $active,
			'crontabRecord' => $crontabRecord,
		);

		$orchestration = self::$client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		// job detail
		$job = self::$client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		$errorsCount = 0;
		$cancelledCount = 0;
		$successCount = 0;
		$processingCount = 0;
		$warnCount = 0;
		$otherCount = 0;

		sleep(2);
		$jobs = self::$client->getOrchestrationJobs($orchestration['id']);
		foreach ($jobs AS $job) {
			if ($job['status'] === 'success') {
				$successCount++;
				continue;
			}

			if ($job['status'] === 'processing') {
				$processingCount++;
				continue;
			}

			if ($job['status'] === 'warning') {
				$warnCount++;
				continue;
			}

			if ($job['status'] === 'error') {
				$errorsCount++;
				continue;
			}

			if ($job['status'] === 'cancelled') {
				$cancelledCount++;
				continue;
			}

			if ($job['status'] === 'terminated') {
				$cancelledCount++;
				continue;
			}

			if ($job['status'] === 'terminating') {
				$cancelledCount++;
				continue;
			}

			$otherCount++;
		}

		$this->assertLessThan(1, $successCount, "Result of API command 'getOrchestrationJobs' should return any job with 'status' status");
		$this->assertLessThan(1, $errorsCount, "Result of API command 'getOrchestrationJobs' should return any job with 'error' status");
		$this->assertGreaterThan(0, $warnCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'warning' status");
		$this->assertLessThan(1, $otherCount, "Result of API command 'getOrchestrationJobs' should return only finished jobs");

		// delete orchestration
		$result = self::$client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
	}

	public function testOrchestrationAsync()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestData());

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		$task = $tasks[0];

		$this->assertEquals(true, $task['active'], "Result of API command 'updateOrchestration' should return enabled orchestration task");

		// enqueue job
		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$this->assertArrayHasKey('status', $job);
		$this->assertEquals('success', $job['status'], "Asynchronous test should end with success status");

		// delete orchestration
		$result = self::$client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
	}

	public function testOrchestrationRunWithEmails()
	{
		// create orchestration
		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// update tasks
		$tasks = self::$client->updateTasks($orchestration['id'], $this->createTestData());

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));


		$notifications = array(FUNCTIONAL_ERROR_NOTIFICATION_EMAIL);

		// new run
		$job = self::$client->runOrchestration($orchestration['id'], $notifications);

		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('notificationsEmails', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);
		$this->assertEquals($notifications, $job['notificationsEmails']);

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}


		// BC old run
		$job = self::$client->createJob($orchestration['id'], $notifications);

		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('notificationsEmails', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);
		$this->assertEquals($notifications, $job['notificationsEmails']);

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}
	}

	public function testOrchestrationRunWithTasks()
	{
		// create orchestration
		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// update tasks
		$testTasks = $this->createTestData();
		$testTasks[0]->setActive(false);

		$tasks = self::$client->updateTasks($orchestration['id'], $testTasks);

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		// new
		$testTasks[0]->setActive(true);
		$job = self::$client->runOrchestration($orchestration['id'], array(), array($testTasks[0]->toArray()));

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

		$this->assertArrayHasKey('tasks', $job);
		$this->assertCount(1, $job['tasks']);

		$task = reset($job['tasks']);

		$this->assertArrayHasKey('active', $task);
		$this->assertTrue($task['active']);

		$this->assertArrayHasKey('results', $job);
		$this->assertArrayHasKey('tasks', $job['results']);
		$this->assertArrayHasKey('phases', $job['results']);
		$this->assertCount(1, $job['results']['tasks']);

		$task = reset($job['results']['tasks']);

		$this->assertArrayHasKey('active', $task);
		$this->assertTrue($task['active']);
		$this->assertArrayHasKey('status', $task);
		$this->assertEquals('success', $task['status']);
	}

	public function testOrchestrationRunWithTasksError()
	{
		// create orchestration
		$orchestration = self::$client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// update tasks
		$testTasks = $this->createTestData();

		$tasks = self::$client->updateTasks($orchestration['id'], $testTasks);

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		// new
		$testTasks[0]->setAction(str_repeat($testTasks[0]->getAction(), 2));

		try {
			self::$client->runOrchestration($orchestration['id'], array(), array($testTasks[0]->toArray()));
			$this->fail('Orchestration run with different tasks should produce errors');
		} catch (CommandClientException $e) {
			$response = json_decode($e->getResponse()->getBody()->getContents(), true);

			$this->assertArrayHasKey('message', $response);
			$this->assertArrayHasKey('code', $response);
			$this->assertArrayHasKey('status', $response);
			$this->assertRegExp('/different from orchestration task/ui', $response['message']);
			$this->assertEquals('warning', $response['status']);
			$this->assertEquals('JOB_VALIDATION', $response['code']);
		}
	}
}
