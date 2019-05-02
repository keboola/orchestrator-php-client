<?php
namespace Keboola\Tests\Orchestrator;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Keboola\Orchestrator\OrchestrationTask;

class FunctionalTest extends AbstractFunctionalTest
{
	private function createTestDataWithWarn()
	{
		$tasks = array();

		array_push($tasks, (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tokens/')
			->setComponent(self::TESTING_COMPONENT_ID)
			->setContinueOnFailure(true));

		array_push($tasks, (new OrchestrationTask())
			->setComponentUrl('https://syrup.keboola.com/timeout/timer')
			->setActionParameters(array('sleep' => 3))
		);


		return $tasks;
	}

	public function testOrchestrationsWarnInResult()
	{
		// create orchestrations
		$masterOrchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $masterOrchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $masterOrchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $masterOrchestration, "Result of API command 'createOrchestration' should return orchestration info");

		$childOrchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $childOrchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $childOrchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $childOrchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// setup tasks
		$this->client->updateTasks($childOrchestration['id'], $this->createTestDataWithWarn());

		$task = new OrchestrationTask();
		$task->setComponentUrl(FUNCTIONAL_ORCHESTRATOR_API_URL . '/run')->setActionParameters(['config' => $childOrchestration['id']]);

		$this->client->updateTasks($masterOrchestration['id'], [$task]);

		$job = $this->client->runOrchestration($masterOrchestration['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'createJob' should contain new created job ID");
		$this->assertArrayHasKey('isFinished', $job, "Result of API command 'createJob' should contain isFinished status");

		$job = $this->waitForJobFinish($job['id']);

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

	public function testOrchestrationRunWithEmails()
	{
		// create orchestration
		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// update tasks
		$sapiTask = new OrchestrationTask();
		$sapiTask->setActive(true)
			->setContinueOnFailure(true)
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters(array('config' => $this->testComponentConfigurationId))
		;

		$tasks = $this->client->updateTasks($orchestration['id'], array($sapiTask));

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		$notifications = array(FUNCTIONAL_ERROR_NOTIFICATION_EMAIL);

		// new run
		$job = $this->client->runOrchestration($orchestration['id'], $notifications);

		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('notificationsEmails', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);
		$this->assertEquals($notifications, $job['notificationsEmails']);

		$job = $this->waitForJobFinish($job['id']);

		$this->assertArrayHasKey('status', $job);
		$this->assertEquals('success', $job['status']);

		// BC old run
		$job = $this->client->createJob($orchestration['id'], $notifications);

		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('notificationsEmails', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);
		$this->assertEquals($notifications, $job['notificationsEmails']);

		$job = $this->waitForJobFinish($job['id']);

		$this->assertArrayHasKey('status', $job);
		$this->assertEquals('success', $job['status']);
	}

	public function testOrchestrationRunWithTasks()
	{
		// create orchestration
		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// update tasks
		$sapiTask = new OrchestrationTask();
		$sapiTask->setActive(false)
			->setContinueOnFailure(false)
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters(array('config' => $this->testComponentConfigurationId))
		;

		$tasks = $this->client->updateTasks($orchestration['id'], array($sapiTask));

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		// new
		$job = $this->client->runOrchestration($orchestration['id'], array(), array($sapiTask->setActive(true)->toArray()));


		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('orchestrationId', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);

		$job = $this->waitForJobFinish($job['id']);

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
		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// update tasks
		$sapiTask = new OrchestrationTask();
		$sapiTask->setActive(false)
			->setContinueOnFailure(false)
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters(array('config' => $this->testComponentConfigurationId))
		;

		$tasks = $this->client->updateTasks($orchestration['id'], array($sapiTask));

		$this->assertCount(1, $tasks, sprintf("Result of API command 'updateTasks' should return %i tasks", 1));

		// new
		$sapiTask->setAction('test');

		try {
			$this->client->runOrchestration($orchestration['id'], array(), array($sapiTask->toArray()));
			$this->fail('Orchestration run with different tasks should produce errors');
		} catch (ClientErrorResponseException $e) {
			$response = $e->getResponse()->json();

			$this->assertArrayHasKey('message', $response);
			$this->assertArrayHasKey('code', $response);
			$this->assertArrayHasKey('status', $response);
			$this->assertRegExp('/different from orchestration task/ui', $response['message']);
			$this->assertEquals('warning', $response['status']);
			$this->assertEquals('JOB_VALIDATION', $response['code']);
		}
	}
}
