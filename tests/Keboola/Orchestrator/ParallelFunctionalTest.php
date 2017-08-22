<?php
namespace Keboola\Orchestrator\Tests;

use Keboola\Orchestrator\Client AS OrchestratorApi;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client AS StorageApi;

class ParallelFunctionalTest extends \PHPUnit_Framework_TestCase
{
	/**
	 * @var OrchestratorApi
	 */
	private $client;

	/**
	 * @var StorageApi
	 */
	private $sapiClient;

	const TESTING_ORCHESTRATION_NAME = 'PHP Client test';

	public function setUp()
	{
		$this->client = OrchestratorApi::factory(array(
			'url' => FUNCTIONAL_ORCHESTRATOR_API_URL,
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN
		));

		$this->sapiClient = new StorageApi(array(
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN,
			'url' => defined('FUNCTIONAL_SAPI_URL') ? FUNCTIONAL_SAPI_URL : null
		));
		$this->sapiClient->verifyToken();

		// clean old tests
		$this->cleanWorkspace();
	}

	private function cleanWorkspace()
	{
		$orchestrations = $this->client->getOrchestrations();

		foreach ($orchestrations AS $orchestration) {
			if (strpos($orchestration['name'], self::TESTING_ORCHESTRATION_NAME) === false)
				continue;

			$this->client->deleteOrchestration($orchestration['id']);
		}
	}

	private function createTestData()
	{
		$tasks = array(
			(new OrchestrationTask())
				->setComponentUrl('https://syrup.keboola.com/timeout/timer')
				->setActionParameters(array('sleep' => 30))
		);

		return $tasks;
	}

	public function testOrchestrationsError()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = $this->client->updateTasks($orchestration['id'], $this->createTestData());

		// orchestration detail
		$orchestration = $this->client->getOrchestration($orchestration['id']);
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
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => 10,
					'actionParameters' => array(
						'delay' => 180,
					)
				),
				1 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => 10,
					'actionParameters' => array(
						'delay' => 20,
						'status' => 'error',
					)
				),
				2 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => null,
					'actionParameters' => array(
						'delay' => 20,
					)
				),
			),
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = $this->client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
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

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = $this->client->updateTasks($orchestration['id'], $this->createTestData());

		// orchestration detail
		$orchestration = $this->client->getOrchestration($orchestration['id']);
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
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => 10,
					'actionParameters' => array(
						'delay' => 180,
					)
				),
				1 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => 10,
					'actionParameters' => array(
						'delay' => 20,
					)
				),
				2 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => null,
					'actionParameters' => array(
						'delay' => 20,
					)
				),
			),
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = $this->client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
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
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "first phase",
					'actionParameters' => array(
						'delay' => 180,
					)
				),
				2 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => null,
					'actionParameters' => array(
						'delay' => 5,
					)
				),
				3 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "0",
					'actionParameters' => array(
						'delay' => 5,
					)
				),
				4 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "",
					'actionParameters' => array(
						'delay' => 5,
					)
				),
			),
		);

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

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
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "first phase",
					'actionParameters' => array(
						'delay' => 180,
					)
				),
				1 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "first phase",
					'actionParameters' => array(
						'delay' => 30,
					)
				),
				2 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => null,
					'actionParameters' => array(
						'delay' => 5,
					)
				),
				3 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "0",
					'actionParameters' => array(
						'delay' => 5,
					)
				),
				4 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "",
					'actionParameters' => array(
						'delay' => 5,
					)
				),
			),
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

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
		$tasks = $this->client->updateTasks($orchestration['id'], $this->createTestData());

		// orchestration detail
		$orchestration = $this->client->getOrchestration($orchestration['id']);
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
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "first phase",
					'actionParameters' => array(
						'delay' => 180,
					)
				),
				1 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "first phase",
					'actionParameters' => array(
						'delay' => 30,
					)
				),
				2 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => null,
					'actionParameters' => array(
						'delay' => 5,
					)
				),
				3 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "0",
					'actionParameters' => array(
						'delay' => 5,
					)
				),
				4 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => "",
					'actionParameters' => array(
						'delay' => 5,
					)
				),
			),
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

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
		$job = $this->client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
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
		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

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
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/jobs',
					'active' => true,
					'phase' => 10,
					'actionParameters' => array(
						'delay' => 60,
					)
				),
			),
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);
		$this->assertEquals($options['notifications'][0]['email'], $orchestration['notifications'][0]['email']);

		$job = $this->client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertArrayHasKey('startTime', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);

		// wait for job start
		while (!$job['startTime']) {
			sleep(2);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('startTime', $job);
			$this->assertArrayHasKey('isFinished', $job);
		}

		// orchestration update
		$options['notifications'][0]['email'] = 'test' . FUNCTIONAL_ERROR_NOTIFICATION_EMAIL;
		$options['tasks'][0]['actionParameters'] = ['delay' => 360];

		$changedOrchestration = $this->client->updateOrchestration($orchestration['id'], $options);
		unset($changedOrchestration['lastExecutedJob']);

		// wait for processing job
		while (!$job['isFinished']) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('isFinished', $job);
		}

		$this->assertArrayHasKey('status', $job);

		// compare orchestration configs
		$finishedOrchestration = $this->client->getOrchestration($orchestration['id']);
		unset($finishedOrchestration['lastExecutedJob']);

		$this->assertEquals($changedOrchestration, $finishedOrchestration);
	}
}
