<?php
/**
 * Created by PhpStorm.
 * User: erik
 * Date: 28/03/14
 * Time: 10:27
 */
namespace Keboola\Orchestrator;

use GuzzleHttp\Command\Guzzle\Description;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Command\Guzzle\Serializer;
use Keboola\Orchestrator\GuzzleServices\RequestLocation\RawBodyLocation;

class Client extends GuzzleClient
{
	const USER_AGENT = 'Keboola Orchestrator PHP Client';

	/**
	 * Configuraiton params
	 * - token - Storage API token
	 * - url (optional) - API URL endpoint
	 *
	 * @param array $config
	 * @return Client
	 */
	public static function factory($config = array())
	{
		$serviceJson = json_decode(
			file_get_contents(__DIR__ . '/service.json'),
			true
		);

		if (!isset($config['token'])) {
			throw new \InvalidArgumentException('Parameter "token" missing in client configuration');
		}

		if (isset($config['url'])) {
			$serviceJson['baseUri'] = $config['url'];
		}

		$serviceDescription = new Description($serviceJson);

		$client = new HttpClient([
			'headers' => [
				'X-StorageApi-Token' => $config['token'],
				'User-Agent' => Client::USER_AGENT,
			]
		]);

		$serializer = new Serializer($serviceDescription, [
			'bodyJsonString' => new RawBodyLocation(),
		]);


		$client = new self($client, $serviceDescription, $serializer);

		//@TODO backoff

		return $client;
	}

	/**
	 * Get registered orchestrations
	 *
	 * @return array
	 */
	public function getOrchestrations()
	{
		$command = $this->getCommand('GetOrchestrations');

		return $this->execute($command);
	}

	/**
	 * Orchestrator registration
	 *
	 * Available options are
	 *	- crontabRecord
	 *  - tokenId
	 *
	 * @param $name
	 * @param array $options
	 * @return array
	 */
	public function createOrchestration($name, $options = array())
	{
		$params = $options;
		$params['name'] = $name;

		$command = $this->getCommand(
			'CreateOrchestration',
			$params
		);

		return $this->execute($command);
	}

	/**
	 * Update orchestration
	 *
	 * Available options are
	 * 	- active
	 *	- crontabRecord
	 *  - tokenId
	 *  - name
	 *
	 * @param int $orchestrationId
	 * @param array $options
	 * @return mixed
	 */
	public function updateOrchestration($orchestrationId, $options = array())
	{
		$params = $options;
		$params['orchestrationId'] = $orchestrationId;

		$command = $this->getCommand(
			'UpdateOrchestration',
			$params
		);

		return $this->execute($command);
	}

	/**
	 * Get orchestration details
	 *
	 * @param int $orchestrationId
	 * @return array
	 */
	public function getOrchestration($orchestrationId)
	{
		$command = $this->getCommand(
			'GetOrchestration',
			array(
				'orchestrationId' => $orchestrationId
			)
		);

		return $this->execute($command);
	}

	/**
	 * List orchestration jobs
	 *
	 * @param int $orchestrationId
	 * @return array
	 */
	public function getOrchestrationJobs($orchestrationId)
	{
		$command = $this->getCommand(
			'GetOrchestrationJobs',
			array(
				'orchestrationId' => $orchestrationId
			)
		);

		return $this->execute($command);
	}

	/**
	 * Delete orchestration
	 *
	 * @param $orchestrationId
	 * @return bool
	 */
	public function deleteOrchestration($orchestrationId)
	{
		$command = $this->getCommand(
			'DeleteOrchestration',
			array(
				'orchestrationId' => $orchestrationId
			)
		);

		$result = $this->execute($command);
		if ($result['status'] != 204) {
			return false;
		}

		return true;
	}

	/**
	 * Get job details
	 *
	 * @param int $jobId
	 * @return array
	 */
	public function getJob($jobId)
	{
		$command = $this->getCommand(
			'GetJob',
			array(
				'jobId' => $jobId
			)
		);

		return $this->execute($command);
	}

	/**
	 * Manualy execute orchestration
	 *
	 * @param int $orchestrationId
	 * @param array $notificationsEmails
	 * @return array
	 * @deprecated This method will be removed in next release
	 */
	public function createJob($orchestrationId, $notificationsEmails = array())
	{
		$command = $this->getCommand(
			'CreateJob',
			array(
				'orchestrationId' => $orchestrationId,
				'notificationsEmails' => $notificationsEmails,
			)
		);

		return $this->execute($command);
	}

	/**
	 * Manualy execute orchestration
	 *
	 * @param $orchestrationId
	 * @param array $notificationsEmails
	 * @return mixed
	 */
	public function runOrchestration($orchestrationId, $notificationsEmails = array(), $tasks = array())
	{
		$params = array('config' => $orchestrationId);

		if ($notificationsEmails) {
			$params['notificationsEmails'] = $notificationsEmails;
		}

		if ($tasks) {
			$params['tasks'] = $tasks;
		}

		$command = $this->getCommand('RunOrchestration',$params);

		return $this->execute($command);
	}

	/**
	 * Cancel waiting job
	 *
	 * @param int $jobId
	 * @return bool
	 */
	public function cancelJob($jobId)
	{
		$command = $this->getCommand(
			'CancelJob',
			array(
				'jobId' => $jobId
			)
		);

		$result = $this->execute($command);
		if ($result['status'] != 204) {
			return false;
		}

		return true;
	}

	/**
	 * Update orchestration tasks
	 *
	 * @param int $orchestrationId
	 * @param OrchestrationTask[] $tasks
	 * @return mixed
	 */
	public function updateTasks($orchestrationId, $tasks = array())
	{
		foreach ($tasks AS $task) {
			if (!$task instanceof OrchestrationTask)
				throw new \InvalidArgumentException(sprintf('Task must be instance of %s', '\Keboola\Orchestrator\OrchestrationTask'));
		}

		$tasks = array_map(
			function(OrchestrationTask $item) {
				return $item->toArray();
			},
			$tasks
		);

		$params = array('tasks' => json_encode($tasks));
		$params['orchestrationId'] = $orchestrationId;

		$command = $this->getCommand(
			'UpdateOrchestrationTasks',
			$params
		);

		return $this->execute($command);
	}
}