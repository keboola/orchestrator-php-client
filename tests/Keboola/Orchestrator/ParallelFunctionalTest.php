<?php
namespace Keboola\Orchestrator\Tests;

use Keboola\Orchestrator\OrchestrationTask;
use Keboola\Tests\Orchestrator\AbstractFunctionalTest;

class ParallelFunctionalTest extends AbstractFunctionalTest
{
	/**
	 * @param string $configurationId
	 * @param string|null $phaseName
	 * @return OrchestrationTask
	 */
	private function createTestOrchestrationTask($configurationId, $phaseName = null)
	{
		return (new OrchestrationTask())
			->setComponent(self::TESTING_COMPONENT_ID)
			->setAction('run')
			->setActionParameters(['config' => $configurationId])
			->setPhase($phaseName)
		;
	}

	public function testOrchestrations()
	{
		$testComponentConfiguration2 = $this->createTestExtractor();

		// create orchestration
		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		$tasks = [];

		// first phase
		$tasks[] = $this->createTestOrchestrationTask($this->testComponentConfigurationId, 10);
		$tasks[] = $this->createTestOrchestrationTask($testComponentConfiguration2['id'], 10);

		// second phase
		$tasks[] = $this->createTestOrchestrationTask($this->testComponentConfigurationId);

		// third phase
		$tasks[] = $this->createTestOrchestrationTask($this->testComponentConfigurationId, 0);

		// fourth phase
		$tasks[] = $this->createTestOrchestrationTask($this->testComponentConfigurationId, 'test');

		$tasks = $this->client->updateTasks($orchestration['id'], $tasks);

		// run orchestration
		$job = $this->client->runOrchestration($orchestration['id']);
		$job = $this->waitForJobFinish($job['id']);

		$this->assertArrayHasKey('status', $job);
		$this->assertEquals('success', $job['status']);

		$this->assertCount(count($tasks), $job['results']['tasks']);
		$this->assertCount(4, $job['results']['phases']);

		$previousPhaseEndTimestamp = null;

		// phases - sequential processing test
		foreach ($job['results']['phases'] as $phase) {
			$currentPhaseEndTimestamp = null;

			$this->assertGreaterThan(0, count($phase));

			foreach ($phase as $task) {
				$this->assertArrayHasKey('status', $task);
				$this->assertEquals('success', $task['status']);

				$this->assertArrayHasKey('startTime', $task);
				$this->assertArrayHasKey('endTime', $task);

				$taskStartTimestamp = (new \DateTime($task['startTime']))->getTimestamp();
				$taskEndTimestamp = (new \DateTime($task['endTime']))->getTimestamp();

				if ($taskEndTimestamp > $currentPhaseEndTimestamp) {
					$currentPhaseEndTimestamp = $taskEndTimestamp;
				}

				$this->assertGreaterThanOrEqual($previousPhaseEndTimestamp, $taskStartTimestamp);
			}

			$previousPhaseEndTimestamp = $currentPhaseEndTimestamp;
		}

		// tasks - parallel processing test
		$phaseTasks = $job['results']['phases'][0];
		$this->assertCount(2, $phaseTasks);

		$task1 = $phaseTasks[0];
		$task2 = $phaseTasks[0];

		$task1StartTimestamp = (new \DateTime($task1['startTime']))->getTimestamp();
		$task1EndTimestamp = (new \DateTime($task['endTime']))->getTimestamp();

		$task2StartTimestamp = (new \DateTime($task2['startTime']))->getTimestamp();
		$task2EndTimestamp = (new \DateTime($task2['endTime']))->getTimestamp();

		$this->assertGreaterThan($task2StartTimestamp, $task1EndTimestamp);
		$this->assertGreaterThan($task1StartTimestamp, $task2EndTimestamp);
	}

	public function testOrchestrationConfigOverwrite()
	{
		$orchestration = $this->client->createOrchestration(sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, uniqid()));

		// orchestration update
		$options = [
			'active' => false,
			'notifications' => [
				[
					"channel" => "error",
					"email" => FUNCTIONAL_ERROR_NOTIFICATION_EMAIL,
				]
			],
			'tasks' => [
				$this->createTestOrchestrationTask($this->testComponentConfigurationId, 1)->toArray(),
				$this->createTestOrchestrationTask($this->testComponentConfigurationId, 2)->toArray(),
				$this->createTestOrchestrationTask($this->testComponentConfigurationId, 3)->toArray(),
			],
		];

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $options);
		$this->assertEquals($options['notifications'][0]['email'], $orchestration['notifications'][0]['email']);

		$job = $this->client->runOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $job);
		$this->assertArrayHasKey('status', $job);
		$this->assertArrayHasKey('isFinished', $job);
		$this->assertArrayHasKey('startTime', $job);
		$this->assertEquals('waiting', $job['status']);
		$this->assertEquals($orchestration['id'], $job['orchestrationId']);

		$this->waitForJobStart($job['id']);

		// orchestration update
		$options['notifications'][0]['email'] = 'test' . FUNCTIONAL_ERROR_NOTIFICATION_EMAIL;
		$options['tasks'][0]['actionParameters'] = ['delay' => 360];

		$changedOrchestration = $this->client->updateOrchestration($orchestration['id'], $options);
		unset($changedOrchestration['lastExecutedJob']);

		$job = $this->client->getJob($job['id']);

		$this->assertArrayHasKey('isFinished', $job);
		$this->assertFalse($job['isFinished']);

		$this->waitForJobFinish($job['id']);

		// compare orchestration configs
		$finishedOrchestration = $this->client->getOrchestration($orchestration['id']);
		unset($finishedOrchestration['lastExecutedJob']);

		$this->assertEquals($changedOrchestration, $finishedOrchestration);
	}
}
