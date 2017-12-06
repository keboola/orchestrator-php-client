<?php
namespace Keboola\Orchestrator\Tests;

use Keboola\Orchestrator\Client AS OrchestratorApi;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client AS StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use PHPUnit\Framework\TestCase;

class ParallelFunctionalTest extends TestCase
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

	private static $testComponentConfigId1 = null;
	private static $testComponentConfigId2 = null;

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

		self::$testComponentConfigId1 = self::createTestExtractor();
		self::$testComponentConfigId2 = self::createTestExtractor();
	}

	public function setUp()
	{
		$orchestrations = self::$client->getOrchestrations();

		foreach ($orchestrations AS $orchestration) {
			if (strpos($orchestration['name'], FunctionalTest::TESTING_ORCHESTRATION_NAME) === false)
				continue;

			self::$client->deleteOrchestration($orchestration['id']);
		}
	}

	private static function createTestExtractor($queryWait = null)
	{
		// create configuration
		$components = new Components(self::$sapiClient);

		if ($queryWait) {
			$parameters = [
				'tables' => [
					[
						'id' => 1,
						'outputTable' => 'in.c-php-orchestrator-tests.time',
						'name' => 'Test Query',
						'query' => 'SELECT SYSTEM$WAIT(' . $queryWait . ') AS "sample"',
						'enabled' => true,
					]
				]
			];
		} else {
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

		}

		$configuration = new Configuration();
		$configuration->setComponentId(FunctionalTest::TESTING_COMPONENT_ID);
		$configuration->setName(FunctionalTest::TESTING_ORCHESTRATION_NAME);
		$configuration->setDescription('used in orchestrator functional tests');
		$configuration->setConfiguration(['parameters' => $parameters]);

		$result = $components->addConfiguration($configuration);

		// create workspace
		$workspace = $components->createConfigurationWorkspace(FunctionalTest::TESTING_COMPONENT_ID, $result['id']);

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
	 * @return OrchestrationTask
	 */
	private function createTestTask($configId)
	{
		return (new OrchestrationTask())
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters([
				'config' => $configId,
			])
		;
	}

	public function testOrchestrationsError()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', FunctionalTest::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], [$this->createTestTask(self::$testComponentConfigId1)]);

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
			'tasks' => array(
				0 => $this->createTestTask(self::$testComponentConfigId1)->setPhase(10)->toArray(),
				1 => $this->createTestTask(self::$testComponentConfigId2)->setPhase(10)->setActionParameters(['configData' => ['configData' => []]])->toArray(),
				2 => $this->createTestTask(self::$testComponentConfigId1)->toArray(),
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

		$this->assertArrayHasKey('status', $job);

		// phases and tasks results in response
		$this->assertArrayHasKey('results', $job, "Result of API command 'getJob' should return results");

		$results = $job['results'];
		$this->assertArrayHasKey('tasks', $results, "Result of API command 'getJob' should return tasks results");
		$this->assertArrayHasKey('phases', $results, "Result of API command 'getJob' should return phases results");
		$this->assertEquals('error', $job['status'], "Result of API command 'getJob' should return job with success status");

		$this->assertCount(2, $results['phases'], "Result of API command 'getJob' should return 2 phases");
		$this->assertCount(3, $results['tasks'], "Result of API command 'getJob' should return 3 tasks");

		// task status
		$successCount = 0;
		$errorsCount = 0;
		$nothingCount = 0;
		foreach ($results['tasks'] AS $taskResult) {
			$this->assertArrayHasKey('status', $taskResult, "Task result should contains execution status");
			if ($taskResult['status'] === 'success') {
				$successCount++;
			}
			if ($taskResult['status'] === 'error') {
				$errorsCount++;
			}
			if (!$taskResult['status']) {
				$nothingCount++;
			}
		}

		$this->assertEquals(1, $successCount, "Only one executed tasks should have 'success' status");
		$this->assertEquals(1, $errorsCount, "Only one executed tasks should have 'error' status");
		$this->assertEquals(1, $nothingCount, "Only one executed tasks should not been processed");

		// task execution order
		$i = 0;
		$taskOrder = array();
		foreach ($results['tasks'] AS $taskResult) {
			$taskOrder[$taskResult['id']] = $i;
			$i++;
		}

		$i = 0;
		foreach ($results['phases'] AS $phase) {
			foreach ($phase AS $taskResult) {
				$this->assertEquals($i, $taskOrder[$taskResult['id']], "Tasks in tasks result and phases result should have same order");
				$i++;
			}
		}

		// parallel job processing
		$phase = $results['phases'][0];
		$task1Start = new \DateTime($phase[0]['startTime']);
		$task1End = new \DateTime($phase[0]['endTime']);

		$task2Start = new \DateTime($phase[1]['startTime']);
		$task2End = new \DateTime($phase[1]['endTime']);

		$validTime = false;
		if ($task1Start->getTimestamp() < $task2Start->getTimestamp()) {
			if ($task1End->getTimestamp() > $task2End->getTimestamp()) {
				$validTime = true;
			}
		}

		$this->assertTrue($validTime, 'First phase should have parallel processed tasks');
	}

	public function testOrchestrations()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', FunctionalTest::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], [$this->createTestTask(self::$testComponentConfigId1)]);

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
			'tasks' => array(
				0 => $this->createTestTask(self::createTestExtractor(100))->setPhase(10)->toArray(),
				1 => $this->createTestTask(self::$testComponentConfigId2)->setPhase(10)->toArray(),
				2 => $this->createTestTask(self::$testComponentConfigId1)->toArray(),
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

		$this->assertArrayHasKey('status', $job);

		// phases and tasks results in response
		$this->assertArrayHasKey('results', $job, "Result of API command 'getJob' should return results");

		$results = $job['results'];
		$this->assertArrayHasKey('tasks', $results, "Result of API command 'getJob' should return tasks results");
		$this->assertArrayHasKey('phases', $results, "Result of API command 'getJob' should return phases results");
		$this->assertEquals('success', $job['status'], "Result of API command 'getJob' should return job with success status");

		$this->assertCount(2, $results['phases'], "Result of API command 'getJob' should return 2 phases");
		$this->assertCount(3, $results['tasks'], "Result of API command 'getJob' should return 3 tasks");

		// task status
		$successCount = 0;
		foreach ($results['tasks'] AS $taskResult) {
			$this->assertArrayHasKey('status', $taskResult, "Task result should contains execution status");
			if ($taskResult['status'] === 'success') {
				$successCount++;
			}
		}

		$this->assertEquals(3, $successCount, "All executed tasks should have 'success' status");

		// task execution order
		$i = 0;
		$taskOrder = array();
		foreach ($results['tasks'] AS $taskResult) {
			$taskOrder[$taskResult['id']] = $i;
			$i++;
		}

		$i = 0;
		foreach ($results['phases'] AS $phase) {
			foreach ($phase AS $taskResult) {
				$this->assertEquals($i, $taskOrder[$taskResult['id']], "Tasks in tasks result and phases result should have same order");
				$i++;
			}
		}

		// parallel job processing
		$phase = $results['phases'][0];
		$task1Start = new \DateTime($phase[0]['startTime']);
		$task1End = new \DateTime($phase[0]['endTime']);

		$task2Start = new \DateTime($phase[1]['startTime']);
		$task2End = new \DateTime($phase[1]['endTime']);

		$validTime = false;
		if ($task1Start->getTimestamp() < $task2Start->getTimestamp()) {
			if ($task1End->getTimestamp() > $task2End->getTimestamp()) {
				$validTime = true;
			}
		}

		$this->assertTrue($validTime, 'First phase should have parallel processed tasks');
	}

	public function testOrchestrationsPhaseNames()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
			'tasks' => array(
				0 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('first phase')->toArray(),
				2 => $this->createTestTask(self::$testComponentConfigId1)->toArray(),
				3 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('0')->toArray(),
				4 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('')->toArray(),
			),
		);

		$orchestration = self::$client->createOrchestration(sprintf('%s %s', FunctionalTest::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$tasks = $orchestration['tasks'];
		$this->assertCount(4, $tasks);

		$this->assertArrayHasKey('phase', $tasks[0]);
		$this->assertEquals('first phase', $tasks[0]['phase']);

		$this->assertArrayHasKey('phase', $tasks[1]);
		$this->assertNull($tasks[1]['phase']);

		$this->assertArrayHasKey('phase', $tasks[2]);
		$this->assertNotNull($tasks[2]['phase']);
		$this->assertEquals(0, $tasks[2]['phase']);

		$this->assertArrayHasKey('phase', $tasks[3]);
		$this->assertNull($tasks[3]['phase']);

		// update orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
			'tasks' => array(
				0 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('first phase')->toArray(),
				1 => $this->createTestTask(self::$testComponentConfigId2)->setPhase('first phase')->toArray(),
				2 => $this->createTestTask(self::$testComponentConfigId1)->toArray(),
				3 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('0')->toArray(),
				4 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('')->toArray(),
			),
		);

		$orchestration = self::$client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$tasks = $orchestration['tasks'];
		$this->assertCount(5, $tasks);

		$this->assertArrayHasKey('phase', $tasks[0]);
		$this->assertEquals('first phase', $tasks[0]['phase']);

		$this->assertArrayHasKey('phase', $tasks[1]);
		$this->assertEquals('first phase', $tasks[1]['phase']);

		$this->assertArrayHasKey('phase', $tasks[2]);
		$this->assertNull($tasks[2]['phase']);

		$this->assertArrayHasKey('phase', $tasks[3]);
		$this->assertNotNull($tasks[3]['phase']);
		$this->assertEquals(0, $tasks[3]['phase']);

		$this->assertArrayHasKey('phase', $tasks[4]);
		$this->assertNull($tasks[4]['phase']);

		// orchestrations tasks
		$tasks = self::$client->updateTasks($orchestration['id'], [$this->createTestTask(self::$testComponentConfigId1)]);

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
			'tasks' => array(
				0 => $this->createTestTask(self::createTestExtractor(100))->setPhase('first phase')->toArray(),
				1 => $this->createTestTask(self::$testComponentConfigId2)->setPhase('first phase')->toArray(),
				2 => $this->createTestTask(self::$testComponentConfigId1)->toArray(),
				3 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('0')->toArray(),
				4 => $this->createTestTask(self::$testComponentConfigId1)->setPhase('')->toArray(),
			),
		);

		$orchestration = self::$client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		$tasks = $orchestration['tasks'];
		$this->assertCount(5, $tasks);

		$this->assertArrayHasKey('phase', $tasks[0]);
		$this->assertEquals('first phase', $tasks[0]['phase']);

		$this->assertArrayHasKey('phase', $tasks[1]);
		$this->assertEquals('first phase', $tasks[1]['phase']);

		$this->assertArrayHasKey('phase', $tasks[2]);
		$this->assertNull($tasks[2]['phase']);

		$this->assertArrayHasKey('phase', $tasks[3]);
		$this->assertNotNull($tasks[3]['phase']);
		$this->assertEquals(0, $tasks[3]['phase']);

		$this->assertArrayHasKey('phase', $tasks[4]);
		$this->assertNull($tasks[4]['phase']);

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

		// phases and tasks results in response
		$this->assertArrayHasKey('results', $job, "Result of API command 'getJob' should return results");

		$results = $job['results'];
		$this->assertArrayHasKey('tasks', $results, "Result of API command 'getJob' should return tasks results");
		$this->assertArrayHasKey('phases', $results, "Result of API command 'getJob' should return phases results");
		$this->assertEquals('success', $job['status'], "Result of API command 'getJob' should return job with success status");

		$this->assertCount(4, $results['phases']);
		$this->assertCount(5, $results['tasks']);

		// task status
		$successCount = 0;
		foreach ($results['tasks'] AS $taskResult) {
			$this->assertArrayHasKey('status', $taskResult, "Task result should contains execution status");

			if ($taskResult['status'] === 'success') {
				$successCount++;
			}
		}

		$this->assertEquals(5, $successCount, "All executed tasks should have 'success' status");

		// task execution order
		$i = 0;
		$taskOrder = array();
		foreach ($results['tasks'] AS $taskResult) {
			$taskOrder[$taskResult['id']] = $i;
			$i++;
		}

		$i = 0;
		foreach ($results['phases'] AS $phase) {
			foreach ($phase AS $taskResult) {
				$this->assertEquals($i, $taskOrder[$taskResult['id']], "Tasks in tasks result and phases result should have same order");
				$i++;
			}
		}

		// parallel job processing
		$phase = $results['phases'][0];
		$task1Start = new \DateTime($phase[0]['startTime']);
		$task1End = new \DateTime($phase[0]['endTime']);

		$task2Start = new \DateTime($phase[1]['startTime']);
		$task2End = new \DateTime($phase[1]['endTime']);

		$validTime = false;
		if ($task1Start->getTimestamp() < $task2Start->getTimestamp()) {
			if ($task1End->getTimestamp() > $task2End->getTimestamp()) {
				$validTime = true;
			}
		}

		$this->assertTrue($validTime, 'First phase should have parallel processed tasks');
	}
	
	public function testOrchestrationConfigOverwrite()
	{
		$orchestration = self::$client->createOrchestration(sprintf('%s %s', FunctionalTest::TESTING_ORCHESTRATION_NAME, uniqid()));

		// orchestration update
		$options = array(
			'active' => false,
			'notifications' => [
				[
					"channel" => "error",
					"email" => FUNCTIONAL_ERROR_NOTIFICATION_EMAIL,
				]
			],
			'tasks' => array(
				0 => $this->createTestTask(self::$testComponentConfigId1)->setPhase(10)->toArray(),
			),
		);

		$orchestration = self::$client->updateOrchestration($orchestration['id'], $options);
		$this->assertEquals($options['notifications'][0]['email'], $orchestration['notifications'][0]['email']);

		$job = self::$client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertArrayHasKey('startTime', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);

		// wait for job start
		while (!$job['startTime']) {
			sleep(2);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('startTime', $job);
			$this->assertArrayHasKey('isFinished', $job);
		}

		// orchestration update
		$options['notifications'][0]['email'] = 'test' . FUNCTIONAL_ERROR_NOTIFICATION_EMAIL;
		$options['tasks'][0]['actionParameters'] = ['configData' => []];

		$changedOrchestration = self::$client->updateOrchestration($orchestration['id'], $options);
		unset($changedOrchestration['lastExecutedJob']);

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = self::$client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$this->assertArrayHasKey('status', $job);

		// compare orchestration configs
		$finishedOrchestration = self::$client->getOrchestration($orchestration['id']);
		unset($finishedOrchestration['lastExecutedJob']);

		$this->assertEquals($changedOrchestration, $finishedOrchestration);
	}
}
