<?php

namespace Keboola\Tests\Orchestrator;

use Keboola\Orchestrator\Client AS OrchestratorApi;
use Keboola\Orchestrator\OrchestrationTask;
use Keboola\StorageApi\Client AS StorageApi;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    /** @var OrchestratorApi */
    private $client;

    /** @var StorageApi */
    private $sapiClient;

    const TESTING_ORCHESTRATION_NAME = 'PHP Client test';

    const TEST_COMPONENT_ID = 'provisioning';
    const TEST_COMPONENT_ACTION = 'async/docker';

    public function setUp()
    {
        $this->client = OrchestratorApi::factory(array(
            'url' => getenv('ORCHESTRATOR_API_URL'),
            'token' => getenv('ORCHESTRATOR_API_TOKEN')
        ));

        $this->sapiClient = new StorageApi(array(
            'token' => getenv('ORCHESTRATOR_API_TOKEN'),
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

    private function generateTestOrchestrationName($name)
    {
        return sprintf('%s %s', self::TESTING_ORCHESTRATION_NAME, $name);
    }

    public function orchestrationCreateData()
    {
        return [
            'minimal configuration' => [
                'attributes' => [],
                'expectedAttributes' => [
                    'crontabRecord' => null,
                    'active' => true,
                    'tasks' => [],
                    'notifications' => [],
                ],
            ],
            'with crontab' => [
                'attributes' => [
                    'crontabRecord' => '1 1 1 1 1',
                ],
                'expectedAttributes' => [
                    'crontabRecord' => '1 1 1 1 1',
                ],
            ],
            'notifications' => [
                'attributes' => [
                    'notifications' => [
                        [
                            'email' => 'spam@keboola.com',
                            'channel' => 'error',
                        ],
                        [
                            'email' => 'spam@keboola.com',
                            'channel' => 'processing',
                            'parameters' => [
                                'tolerance' => 20,
                            ],
                        ],
                    ],
                ],
                'expectedAttributes' => [
                    'notifications' => [
                        [
                            'email' => 'spam@keboola.com',
                            'channel' => 'error',
                            'parameters' => [],
                        ],
                        [
                            'email' => 'spam@keboola.com',
                            'channel' => 'processing',
                            'parameters' => [
                                'tolerance' => 20,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider orchestrationCreateData
     */
    public function testOrchestrationCreateAndGet(array $attributes, array $expectedAttributes)
    {
        $name = $this->generateTestOrchestrationName('testOrchestrationCreate');
        $orchestration = $this->client->createOrchestration($name, $attributes);

        $this->assertArrayHasKey('id', $orchestration);
        $this->assertRegExp('/^\d+$/', (string)$orchestration['id']);

        $this->assertArrayHasKey('name', $orchestration);
        $this->assertSame($name, $orchestration['name']);

        $this->assertArrayHasKey('createdTime', $orchestration);
        $this->assertNotEmpty($orchestration['createdTime']);

        $this->assertArrayHasKey('token', $orchestration);
        $this->assertNotEmpty('id', $orchestration['token']['id']);
        $this->assertNotEmpty('description', $orchestration['token']['id']);

        $this->assertArrayHasKey('crontabRecord', $orchestration);
        $this->assertArrayHasKey('active', $orchestration);
        $this->assertArrayHasKey('tasks', $orchestration);
        $this->assertArrayHasKey('notifications', $orchestration);
        $this->assertArrayHasKey('nextScheduledTime', $orchestration);

        $this->assertArrayHasKey('uri', $orchestration);
        $this->assertContains(getenv('ORCHESTRATOR_API_URL'), $orchestration['uri']);
        $this->assertContains((string)$orchestration['id'], $orchestration['uri']);

        $this->assertArrayHasKey('lastScheduledTime', $orchestration);
        $this->assertNull($orchestration['lastScheduledTime']);
        $this->assertArrayHasKey('lastExecutedJob', $orchestration);
        $this->assertNull($orchestration['lastExecutedJob']);

        foreach (array_keys($attributes) as $attribute) {
            $this->assertArrayHasKey($attribute, $expectedAttributes);
        }

        $this->assertEquals($orchestration, $this->client->getOrchestration($orchestration['id']));

        foreach ($orchestration as $key => $value) {
            if (!array_key_exists($key, $expectedAttributes)) {
                unset($orchestration[$key]);
            }
        }

        ksort($orchestration);
        ksort($expectedAttributes);

        $this->assertEquals($expectedAttributes, $orchestration);
    }

    public function testOrchestrationsList()
    {
        $this->assertCount(0, $this->client->getOrchestrations());

        $name = $this->generateTestOrchestrationName('testOrchestrationsList');
        $this->client->createOrchestration($name);

        $name = $this->generateTestOrchestrationName('testOrchestrationsList2');
        $orchestration = $this->client->createOrchestration($name);

        $orchestrations = $this->client->getOrchestrations();
        $this->assertCount(2, $orchestrations);

        unset($orchestration['tasks']); // orchestration list do not return task info
        $this->assertEquals($orchestration, end($orchestrations));
    }

    public function testOrchestrationDelete()
    {
        $name = $this->generateTestOrchestrationName('testOrchestrationDelete');
        $orchestration = $this->client->createOrchestration($name);

        $this->assertCount(1, $this->client->getOrchestrations());

        $result = $this->client->deleteOrchestration($orchestration['id']);
        $this->assertTrue($result);

        $this->assertCount(0, $this->client->getOrchestrations());
    }

    public function testOrchestrationCreateWithTasks()
    {
        $task1 = (new OrchestrationTask())
            ->setComponent(self::TEST_COMPONENT_ID)
            ->setAction(self::TEST_COMPONENT_ACTION)
            ->setActive(true)
            ->setContinueOnFailure(false)
            ->setActionParameters(array('type' => 'jupyter'));

		$task2 = (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tokens/')
			->setActive(false)
			->setContinueOnFailure(true)
			->setTimeoutMinutes(10)
			->setPhase(123);

		$task3 = (new OrchestrationTask())
			->setComponentUrl('https://connection.keboola.com/v2/storage/tokens/')
			->setActive(false)
			->setContinueOnFailure(true)
			->setTimeoutMinutes(10)
			->setPhase('my-phase');

		$name = $this->generateTestOrchestrationName('testOrchestrationCreateWithTasks');
        $orchestration = $this->client->createOrchestration($name, array(
            'tasks' => array(
                $task1->toArray(),
                $task2->toArray(),
                $task3->toArray(),
            )
        ));

        $tasksData = $orchestration['tasks'];
        $this->assertCount(3, $tasksData);

        foreach ($tasksData as $taskData) {
            $this->assertArrayHasKey('id', $taskData);
            $this->assertArrayHasKey('actionParameters', $taskData);
            $this->assertArrayHasKey('timeoutMinutes', $taskData);
            $this->assertArrayHasKey('active', $taskData);
            $this->assertArrayHasKey('continueOnFailure', $taskData);
            $this->assertArrayHasKey('phase', $taskData);
        }

        $task1Data = $tasksData[0];
        $this->assertArrayHasKey('component', $task1Data);
        $this->assertSame($task1->getComponent(), $task1Data['component']);
        $this->assertArrayHasKey('action', $task1Data);
        $this->assertSame($task1->getAction(), $task1Data['action']);
        $this->assertArrayNotHasKey('componentUrl', $task1Data);

        $this->assertSame($task1->getActionParameters(), $task1Data['actionParameters']);
        $this->assertNull($task1->getTimeoutMinutes());
        $this->assertTrue($task1Data['active']);
        $this->assertFalse($task1Data['continueOnFailure']);
        $this->assertNull($task1Data['phase']);

        $task2Data = $tasksData[1];
        $this->assertArrayNotHasKey('component', $task2Data);
        $this->assertArrayNotHasKey('action', $task2Data);
        $this->assertArrayHasKey('componentUrl', $task2Data);
        $this->assertSame($task2->getComponentUrl(), $task2Data['componentUrl']);

        $this->assertSame($task2->getActionParameters(), $task2Data['actionParameters']);
        $this->assertNotNull($task2Data['timeoutMinutes']);
        $this->assertSame($task2->getTimeoutMinutes(), $task2Data['timeoutMinutes']);
        $this->assertFalse($task2Data['active']);
        $this->assertTrue($task2Data['continueOnFailure']);
        $this->assertNotNull($task2Data['phase']);
        $this->assertSame('123', $task2Data['phase']);

        $task3Data = $tasksData[2];
        $this->assertArrayNotHasKey('component', $task3Data);
        $this->assertArrayNotHasKey('action', $task3Data);
        $this->assertArrayHasKey('componentUrl', $task3Data);
        $this->assertSame($task3->getComponentUrl(), $task3Data['componentUrl']);

        $this->assertSame($task3->getActionParameters(), $task3Data['actionParameters']);
        $this->assertNotNull($task3Data['timeoutMinutes']);
        $this->assertSame($task3->getTimeoutMinutes(), $task3Data['timeoutMinutes']);
        $this->assertFalse($task3Data['active']);
        $this->assertTrue($task3Data['continueOnFailure']);
        $this->assertNotNull($task3Data['phase']);
        $this->assertSame('my-phase', $task3Data['phase']);

        $this->assertEquals($orchestration, $this->client->getOrchestration($orchestration['id']));
    }

    public function testOrchestrationUpdate()
    {
        $name = $this->generateTestOrchestrationName('testOrchestrationUpdate');
        $orchestration = $this->client->createOrchestration($name);

		$this->assertCount(0, $orchestration['tasks']);

        $task = (new OrchestrationTask())
            ->setComponent(self::TEST_COMPONENT_ID)
            ->setAction(self::TEST_COMPONENT_ACTION)
            ->setActive(true)
            ->setContinueOnFailure(false)
            ->setActionParameters(array('type' => 'jupyter'));


        $options = [
            'name' => $this->generateTestOrchestrationName('testOrchestrationUpdateRenamed'),
            'crontabRecord' => '1 1 1 1 1',
            'active' => false,
            'notifications' => [
                [
                    'email' => 'spam@keboola.com',
                    'channel' => 'error',
                ],
            ],
            'tasks' => [
                $task->toArray(),
            ]
        ];

        $orchestration = $this->client->updateOrchestration($orchestration['id'], $options);

        $this->assertArrayHasKey('name', $orchestration);
        $this->assertArrayHasKey('crontabRecord', $orchestration);
        $this->assertArrayHasKey('active', $orchestration);
        $this->assertArrayHasKey('tasks', $orchestration);
        $this->assertArrayHasKey('notifications', $orchestration);

        $this->assertSame($options['name'], $orchestration['name']);
        $this->assertSame($options['crontabRecord'], $orchestration['crontabRecord']);
        $this->assertFalse($orchestration['active']);

        $this->assertCount(1, $orchestration['tasks']);

        $taskData = $orchestration['tasks'][0];
        $this->assertArrayHasKey('component', $taskData);
        $this->assertSame($task->getComponent(), $taskData['component']);
        $this->assertArrayHasKey('action', $taskData);
        $this->assertSame($task->getAction(), $taskData['action']);
        $this->assertArrayNotHasKey('componentUrl', $taskData);

        $this->assertSame($task->getActionParameters(), $taskData['actionParameters']);
        $this->assertNull($task->getTimeoutMinutes());
        $this->assertTrue($taskData['active']);
        $this->assertFalse($taskData['continueOnFailure']);
        $this->assertNull($taskData['phase']);

        $this->assertCount(1, $orchestration['notifications']);

        $notification = [
            'email' => 'spam@keboola.com',
            'channel' => 'error',
            'parameters' => [],
        ];

        $this->assertSame($notification, $orchestration['notifications'][0]);

        $this->assertEquals($orchestration, $this->client->getOrchestration($orchestration['id']));

		$orchestration = $this->client->updateOrchestration($orchestration['id'], array('tasks' => []));
		$this->assertCount(0, $orchestration['tasks']);

		$orchestration = $this->client->getOrchestration($orchestration['id']);
		$this->assertCount(0, $orchestration['tasks']);
    }

    public function testOrchestrationTasksUpdate()
    {
        $name = $this->generateTestOrchestrationName('testOrchestrationUpdate');
        $orchestration = $this->client->createOrchestration($name);

		$this->assertCount(0, $orchestration['tasks']);

        $task = (new OrchestrationTask())
            ->setComponent(self::TEST_COMPONENT_ID)
            ->setAction(self::TEST_COMPONENT_ACTION)
            ->setActive(true)
            ->setContinueOnFailure(false)
            ->setActionParameters(array('type' => 'jupyter'));


        $tasks = $this->client->updateTasks($orchestration['id'], [$task]);
        $this->assertCount(1, $tasks);

        $taskData = $tasks[0];
        $this->assertArrayHasKey('component', $taskData);
        $this->assertSame($task->getComponent(), $taskData['component']);
        $this->assertArrayHasKey('action', $taskData);
        $this->assertSame($task->getAction(), $taskData['action']);
        $this->assertArrayNotHasKey('componentUrl', $taskData);

        $this->assertSame($task->getActionParameters(), $taskData['actionParameters']);
        $this->assertNull($task->getTimeoutMinutes());
        $this->assertTrue($taskData['active']);
        $this->assertFalse($taskData['continueOnFailure']);
        $this->assertNull($taskData['phase']);

        $orchestration = $this->client->getOrchestration($orchestration['id']);
        $this->assertEquals($tasks, $orchestration['tasks']);

        $orchestration = $this->client->getOrchestration($orchestration['id']);
        $this->assertEquals($tasks, $orchestration['tasks']);

		$tasks = $this->client->updateTasks($orchestration['id'], array());
		$this->assertCount(0, $tasks);

		$orchestration = $this->client->getOrchestration($orchestration['id']);
		$this->assertEquals($tasks, $orchestration['tasks']);
    }
}