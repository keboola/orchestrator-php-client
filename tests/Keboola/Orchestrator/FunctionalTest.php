<?php
namespace Keboola\Orchestrator\Tests;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Keboola\Orchestrator\Client AS OrchestratorApi;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client AS StorageApi;
use Keboola\StorageApi\Table;

class FunctionalTest extends \PHPUnit_Framework_TestCase
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
			$this->sapiClient->dropTable($orchestration['configurationId']);
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

	private function createTestDataWithError()
	{
		$tasks = array();

		array_push($tasks, (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tokens/'));

		array_push($tasks, (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tickets/'));


		return $tasks;
	}

	private function createTestDataWithWarn()
	{
		$tasks = array();

		array_push($tasks, (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tokens/')
			->setContinueOnFailure(true));

		array_push($tasks, (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tickets/'));


		return $tasks;
	}

	public function testOrchestrationTasks()
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
		$ytTask = new OrchestrationTask();
		$ytTask->setComponent('ex-youtube')
			->setAction('configs')
			->setActionParameters(array('name' => 'Test'));

		$sapiTask = new OrchestrationTask();
		$sapiTask->setComponentUrl('https://connection.keboola.com/v2/storage/tickets/');

		$tasks = $this->client->updateTasks($orchestration['id'], array($ytTask, $sapiTask));

		$count = 2;
		$this->assertCount($count, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", $count));

		// modify tasks
		$url = 'https://connection.keboola.com/v2/storage/tickets/';
		$ytTask->setActive(false)
			->setContinueOnFailure(true)
			->setTimeoutMinutes(30)
			->setComponent(null)
			->setComponentUrl($url);

		$tasks = $this->client->updateTasks($orchestration['id'], array($ytTask, $sapiTask));

		$this->assertCount($count, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", $count));

		$task = $tasks[0];

		$this->assertEquals(false, $task['active'], "Result of API command 'updateOrchestration' should return disabled orchestration task");
		$this->assertEquals(true, $task['continueOnFailure'], "Result of API command 'updateOrchestration' should return task with continue on error");
		$this->assertEquals($url, $task['componentUrl'], "Result of API command 'updateOrchestration' should return task with SAPI ticket");

		// erase all tasks
		$count = 0;
		$tasks = $this->client->updateTasks($orchestration['id'], array());

		$this->assertCount($count, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", $count));

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);
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
					'componentUrl' => 'https://syrup.keboola.com/timeout/timer',
					'active' => true,
					'actionParameters' => array(
						'sleep' => 60,
					)
				)
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
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!in_array($job['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$job = $this->client->createJob($orchestration['id']);

		// list of orchestration jobs
		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		$this->assertCount(2, $jobs, "Result of API command 'getOrchestrationJobs' should return 2 jobs");

		$this->assertArrayHasKey('id', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('orchestrationId', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('status', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertEquals($orchestration['id'], $jobs[0]['orchestrationId'], "Result of API command 'getOrchestrationJobs' should return jobs for given orchestration");

		// job detail
		$job = $this->client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		// wait for job processing
		while (in_array($job['status'], array('waiting'))) {
			sleep(3);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$processingJob = $job;

		// job cancel
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		$result = $this->client->cancelJob($job['id']);
		$this->assertTrue($result, "Result of API command 'cancelJob' should return TRUE");

		$job = $this->client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");
		$this->assertEquals('cancelled', $job['status'], "Result of API command 'getJob' should return cancelled job");


		// job stats

		// wait for processing job
		while (!in_array($processingJob['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$processingJob = $this->client->getJob($processingJob['id']);
			$this->assertArrayHasKey('status', $processingJob, "Result of API command 'getJob' should return job info");
		}

		$errorsCount = 0;
		$cancelledCount = 0;
		$successCount = 0;
		$processingCount = 0;
		$warnCount = 0;
		$otherCount = 0;

		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		foreach ($jobs AS $job) {
			if ($job['status'] === 'success') {
				$successCount++;
				continue;
			}

			if ($job['status'] === 'processing') {
				$processingCount++;
				continue;
			}

			if ($job['status'] === 'warn') {
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

			$otherCount++;
		}

		$this->assertLessThan(1, $errorsCount, "Result of API command 'getOrchestrationJobs' should return any job with 'error' status");
		$this->assertLessThan(1, $warnCount, "Result of API command 'getOrchestrationJobs' should return any job with 'warn' status");
		$this->assertGreaterThan(0, $successCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'success' status");
		$this->assertLessThan(1, $otherCount, "Result of API command 'getOrchestrationJobs' should return only finished jobs");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);
	}

	public function testOrchestrationsCreateWithTasksAndNotifications()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
			'tasks' => array(
				0 => array(
					'componentUrl' => 'https://syrup.keboola.com/timeout/timer',
					'active' => true,
					'actionParameters' => array(
						'sleep' => 60,
					)
				)
			),
			'notifications' => array(
				0 => array(
					'channel' => 'error',
					'email' => 'devel@keboola.com',
				)

			)
		);

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$this->assertCount(1, $orchestration['tasks']);
		$task = reset($orchestration['tasks']);

		$this->assertArrayHasKey('id', $task);
		$this->assertArrayHasKey('componentUrl', $task);
		$this->assertArrayHasKey('active', $task);
		$this->assertArrayHasKey('actionParameters', $task);

		$this->assertNotEmpty('id', $task['id']);
		$this->assertEquals('https://syrup.keboola.com/timeout/timer', $task['componentUrl']);
		$this->assertEquals(array('sleep' => 60,), $task['actionParameters']);
		$this->assertTrue($task['active']);

		$this->assertCount(1, $orchestration['notifications']);
		$notification = reset($orchestration['notifications']);

		$this->assertArrayHasKey('email', $notification);
		$this->assertArrayHasKey('channel', $notification);
		$this->assertEquals('devel@keboola.com', $notification['email']);
		$this->assertEquals('error', $notification['channel']);

		// orchestration detail
		$orchestration = $this->client->getOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'getOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('tasks', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");

		$this->assertCount(1, $orchestration['tasks']);
		$task = reset($orchestration['tasks']);

		$this->assertArrayHasKey('id', $task);
		$this->assertArrayHasKey('componentUrl', $task);
		$this->assertArrayHasKey('active', $task);
		$this->assertArrayHasKey('actionParameters', $task);

		$this->assertNotEmpty('id', $task['id']);
		$this->assertEquals('https://syrup.keboola.com/timeout/timer', $task['componentUrl']);
		$this->assertEquals(array('sleep' => 60,), $task['actionParameters']);
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

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestrations tasks
		$tasks = $this->client->updateTasks($orchestration['id'], $this->createTestDataWithError());

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
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!in_array($job['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$job = $this->client->createJob($orchestration['id']);

		// list of orchestration jobs
		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		$this->assertCount(2, $jobs, "Result of API command 'getOrchestrationJobs' should return 2 jobs");

		$this->assertArrayHasKey('id', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('orchestrationId', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('status', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertEquals($orchestration['id'], $jobs[0]['orchestrationId'], "Result of API command 'getOrchestrationJobs' should return jobs for given orchestration");

		// job detail
		$job = $this->client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		// wait for job processing
		while (in_array($job['status'], array('waiting'))) {
			sleep(3);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$processingJob = $job;

		// job stats

		// wait for processing job
		while (!in_array($processingJob['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$processingJob = $this->client->getJob($processingJob['id']);
			$this->assertArrayHasKey('status', $processingJob, "Result of API command 'getJob' should return job info");
		}

		$errorsCount = 0;
		$cancelledCount = 0;
		$successCount = 0;
		$processingCount = 0;
		$warnCount = 0;
		$otherCount = 0;

		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		foreach ($jobs AS $job) {
			if ($job['status'] === 'success') {
				$successCount++;
				continue;
			}

			if ($job['status'] === 'processing') {
				$processingCount++;
				continue;
			}

			if ($job['status'] === 'warn') {
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

			$otherCount++;
		}

		$this->assertLessThan(1, $successCount, "Result of API command 'getOrchestrationJobs' should return any job with 'status' status");
		$this->assertLessThan(1, $warnCount, "Result of API command 'getOrchestrationJobs' should return any job with 'warn' status");
		$this->assertGreaterThan(0, $errorsCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'error' status");
		$this->assertLessThan(1, $otherCount, "Result of API command 'getOrchestrationJobs' should return only finished jobs");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);
	}

	public function testOrchestrationsWarn()
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
		$tasks = $this->client->updateTasks($orchestration['id'], $this->createTestDataWithWarn());

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
		);

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'updateOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertArrayHasKey('active', $orchestration, "Result of API command 'updateOrchestration' should return orchestration info");
		$this->assertEquals($active, $orchestration['active'], "Result of API command 'updateOrchestration' should return disabled orchestration");
		$this->assertEquals($crontabRecord, $orchestration['crontabRecord'], "Result of API command 'updateOrchestration' should return modified orchestration");

		// enqueue job
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!in_array($job['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$job = $this->client->createJob($orchestration['id']);

		// list of orchestration jobs
		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		$this->assertCount(2, $jobs, "Result of API command 'getOrchestrationJobs' should return 2 jobs");

		$this->assertArrayHasKey('id', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('orchestrationId', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertArrayHasKey('status', $jobs[0], "Result of API command 'getOrchestrationJobs' should return orchestration jobs");
		$this->assertEquals($orchestration['id'], $jobs[0]['orchestrationId'], "Result of API command 'getOrchestrationJobs' should return jobs for given orchestration");

		// job detail
		$job = $this->client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		// wait for job processing
		while (in_array($job['status'], array('waiting'))) {
			sleep(3);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$processingJob = $job;

		// job cancel
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");

		$result = $this->client->cancelJob($job['id']);
		$this->assertTrue($result, "Result of API command 'cancelJob' should return TRUE");

		$job = $this->client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");
		$this->assertEquals('cancelled', $job['status'], "Result of API command 'getJob' should return cancelled job");

		// job stats

		// wait for processing job
		while (!in_array($processingJob['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$processingJob = $this->client->getJob($processingJob['id']);
			$this->assertArrayHasKey('status', $processingJob, "Result of API command 'getJob' should return job info");
		}

		$errorsCount = 0;
		$cancelledCount = 0;
		$successCount = 0;
		$processingCount = 0;
		$warnCount = 0;
		$otherCount = 0;

		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		foreach ($jobs AS $job) {
			if ($job['status'] === 'success') {
				$successCount++;
				continue;
			}

			if ($job['status'] === 'processing') {
				$processingCount++;
				continue;
			}

			if ($job['status'] === 'warn') {
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

			$otherCount++;
		}

		$this->assertLessThan(1, $successCount, "Result of API command 'getOrchestrationJobs' should return any job with 'status' status");
		$this->assertLessThan(1, $errorsCount, "Result of API command 'getOrchestrationJobs' should return any job with 'error' status");
		$this->assertGreaterThan(0, $warnCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'warn' status");
		$this->assertLessThan(1, $otherCount, "Result of API command 'getOrchestrationJobs' should return only finished jobs");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);
	}

	public function testOrchestrationBackoff()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// 503 task
		$url = 'https://syrup.keboola.com/timeout/maintenance';
		$sapiTask = new OrchestrationTask();
		$sapiTask->setActive(true)
			->setContinueOnFailure(false)
			->setComponent(null)
			->setComponentUrl($url);

		$tasks = $this->client->updateTasks($orchestration['id'], array($sapiTask));

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		$task = $tasks[0];

		$this->assertEquals(true, $task['active'], "Result of API command 'updateOrchestration' should return enabled orchestration task");
		$this->assertEquals($url, $task['componentUrl'], "Result of API command 'updateOrchestration' should return task with SAPI ticket");

		// enqueue job
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!in_array($job['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$this->assertEquals('success', $job['status'], "Maintenance test should end with success status");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);

	}

	public function testOrchestrationAsyncBackoff()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// 503 task
		$url = 'https://syrup.keboola.com/timeout/asynchronous';
		$sapiTask = new OrchestrationTask();
		$sapiTask->setActive(true)
			->setActionParameters(array('config' => 'maintenance'))
			->setContinueOnFailure(false)
			->setComponent(null)
			->setComponentUrl($url);

		$tasks = $this->client->updateTasks($orchestration['id'], array($sapiTask));

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		$task = $tasks[0];

		$this->assertEquals(true, $task['active'], "Result of API command 'updateOrchestration' should return enabled orchestration task");
		$this->assertEquals($url, $task['componentUrl'], "Result of API command 'updateOrchestration' should return task with SAPI ticket");

		// enqueue job
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!in_array($job['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$this->assertEquals('success', $job['status'], "Asynchronous test should end with success status");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);

	}

	public function testOrchestrationAsync()
	{
		// create orchestration
		$options = array(
			'crontabRecord' => '1 1 1 1 1',
		);

		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()), $options);

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// 503 task
		$url = 'https://syrup.keboola.com/timeout/asynchronous';
		$sapiTask = new OrchestrationTask();
		$sapiTask->setActive(true)
			->setContinueOnFailure(false)
			->setComponent(null)
			->setComponentUrl($url);

		$tasks = $this->client->updateTasks($orchestration['id'], array($sapiTask));

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		$task = $tasks[0];

		$this->assertEquals(true, $task['active'], "Result of API command 'updateOrchestration' should return enabled orchestration task");
		$this->assertEquals($url, $task['componentUrl'], "Result of API command 'updateOrchestration' should return task with SAPI ticket");

		// enqueue job
		$job = $this->client->createJob($orchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'createJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'createJob' should return job info");
		$this->assertEquals('waiting', $job['status'], "Result of API command 'createJob' should return new waiting job");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'createJob' should return new waiting job for given orchestration");

		// wait for processing job
		while (!in_array($job['status'], array('ok', 'success', 'error', 'warn'))) {
			sleep(5);
			$job = $this->client->getJob($job['id']);
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		}

		$this->assertEquals('success', $job['status'], "Asynchronous test should end with success status");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
		$this->sapiClient->dropTable($orchestration['configurationId']);

	}
}
