<?php
namespace Keboola\Orchestrator\Tests;

use Keboola\Orchestrator\Client AS OrchestratorApi;
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

	private $bucketId;
	private $bucketStage;

	public function setUp()
	{
		$this->client = OrchestratorApi::factory(array(
			'url' => FUNCTIONAL_ORCHESTRATOR_API_URL,
			'token' => FUNCTIONAL_ORCHESTRATOR_API_TOKEN
		));

		$this->sapiClient = new StorageApi(FUNCTIONAL_ORCHESTRATOR_API_TOKEN);
		$this->sapiClient->verifyToken();

		$this->bucketId = 'orchestratorTest';
		$this->bucketStage = 'out';

		// clean old tests vcetne orchestraci
		$this->cleanWorkspace();
		$this->initTestData();
	}

	private function cleanWorkspace()
	{
		$buckets = $this->sapiClient->listBuckets();
		$orchestrations = $this->client->getOrchestrations();

		foreach ($buckets AS $bucket) {
			if ($bucket['stage'] != $this->bucketStage || !preg_match("/{$this->bucketId}/", $bucket['id']))
				continue;

			// delete orchestrations
			foreach ($orchestrations AS $orchestration) {
				if ($orchestration['configurationId'] != $bucket['id'])
					continue;

				$this->client->deleteOrchestration($orchestration['id']);
			}

			// delete tables
			$tables = $this->sapiClient->listTables($bucket['id']);
			foreach ($tables AS $table) {
				$this->sapiClient->dropTable($table['id']);
			}

			$this->sapiClient->dropBucket($bucket['id']);
		}
	}

	private function initTestData()
	{
		$this->bucketId = $this->sapiClient->createBucket($this->bucketId . uniqid(), $this->bucketStage, 'Bucket for orchestrator testing');

		$data = array(
			0 => array(
				"id" => $this->sapiClient->generateId(),
				"runUrl" => 'https://connection.keboola.com/v2/storage/tickets/',
				"runParameters" => '{}',
				"timeoutMinutes" => '',
				"active" => '1',
			)
		);

		$table = new Table($this->sapiClient, "{$this->bucketId}.test");
		$table->setHeader(array_keys($data[0]));
		$table->setFromArray($data);

		$table->save(true);
	}

	public function testOrchestrations()
	{
		// create orchestration
		$orchestration = $this->client->createOrchestration("{$this->bucketId}.test", '1 1 1 1 1', 'Testing Orchestration');
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'createOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'createOrchestration' should return orchestration info");

		// orchestration detail
		$orchestration = $this->client->getOrchestration($orchestration['id']);
		$this->assertArrayHasKey('id', $orchestration, "Result of API command 'getOrchestration' should contain new created orchestration ID");
		$this->assertArrayHasKey('crontabRecord', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");
		$this->assertArrayHasKey('nextScheduledTime', $orchestration, "Result of API command 'getOrchestration' should return orchestration info");

		// orchestration update
		$crontabRecord = '* * * * *';
		$active = false;

		$orchestration = $this->client->updateOrchestration($orchestration['id'], $active, $crontabRecord);
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

		// job cancel
		for ($i = 0; $i < 5; $i++) {
			$job = $this->client->createJob($orchestration['id']);
			$job = $this->client->createJob($orchestration['id']);
			$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
			$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
			$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
			$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");
		}

		$result = $this->client->cancelJob($job['id']);
		$this->assertTrue($result, "Result of API command 'cancelJob' should return TRUE");

		$job = $this->client->getJob($job['id']);
		$this->assertArrayHasKey('id', $job, "Result of API command 'getJob' should contain job ID");
		$this->assertArrayHasKey('orchestrationId', $job, "Result of API command 'getJob' should return job info");
		$this->assertArrayHasKey('status', $job, "Result of API command 'getJob' should return job info");
		$this->assertEquals($orchestration['id'], $job['orchestrationId'], "Result of API command 'getJob' should return job for given orchestration");
		$this->assertEquals('canceled', $job['status'], "Result of API command 'getJob' should return canceled job");

		// job processing
		sleep(120);
		$errorsCount = 0;
		$successCount = 0;

		$jobs = $this->client->getOrchestrationJobs($orchestration['id']);
		foreach ($jobs AS $job) {
			if ($job['status'] === 'success')
				$successCount++;

			if ($job['status'] === 'error')
				$errorsCount++;
		}

		$this->assertLessThan(1, $errorsCount, "Result of API command 'getOrchestrationJobs' should return any job with 'error' status");
		$this->assertGreaterThan(0, $successCount, "Result of API command 'getOrchestrationJobs' should return least one job with 'success' status");

		// delete orchestration
		$result = $this->client->deleteOrchestration($orchestration['id']);
		$this->assertTrue($result, "Result of API command 'deleteOrchestration' should return TRUE");
	}
}
