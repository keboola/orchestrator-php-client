<?php
/**
 * Created by PhpStorm.
 * User: erik
 * Date: 28/03/14
 * Time: 10:27
 */
namespace Keboola\Orchestrator;

use Guzzle\Common\Collection;
use Guzzle\Plugin\Backoff\BackoffPlugin;
use Guzzle\Service\Client AS GuzzleClient;
use Guzzle\Service\Description\ServiceDescription;

class Client extends GuzzleClient
{
	const DEFAULT_API_URL = 'https://syrup.keboola.com/orchestrator';

	/**
	 * @param array $config
	 * @return Client
	 */
	public static function factory($config = array())
	{
		$default = array(
			'url' => self::DEFAULT_API_URL,
		);

		$required = array('token');

		$config = Collection::fromConfig($config, $default, $required);
		$config['request.options'] = array(
			'headers' => array(
				'X-StorageApi-Token' => $config->get('token')
			)
		);
		$client = new self($config->get('url'), $config);

		// Attach a service description to the client
		$description = ServiceDescription::factory(__DIR__ . '/service.json');
		$client->setDescription($description);

		$client->setBaseUrl($config->get('url'));

		// Setup exponential backoff
		$backoffPlugin = BackoffPlugin::getExponentialBackoff();
		$client->addSubscriber($backoffPlugin);

		return $client;
	}

	/**
	 * Get registered orchestrations
	 *
	 * @return array
	 */
	public function getOrchestrations()
	{
		$result = $this->getCommand('GetOrchestrations')->execute();
		return $result;
	}

	/**
	 * Orchestrator registration
	 *
	 * @param string $configurationId ID of configuration table in SAPI
	 * @param string $crontabRecord
	 * @param string|null $name
	 * @return array
	 */
	public function createOrchestration($configurationId, $crontabRecord, $name = null)
	{
		$params = array(
			'configurationId' => $configurationId,
			'crontabRecord' => $crontabRecord,
		);

		if ($name !== null)
			$params['name'] = $name;

		$result = $this->getCommand(
			'CreateOrchestration',
			$params
		)->execute();

		return $result;
	}

	/**
	 * Update orchestration
	 *
	 * @param int $orchestrationId
	 * @param boolean $active
	 * @param string $crontabRecord
	 * @return mixed
	 */
	public function updateOrchestration($orchestrationId, $active = null, $crontabRecord = null)
	{
		$params = array('orchestrationId' => $orchestrationId);

		if ($active !== null)
			$params['active'] = (bool) $active;

		if ($crontabRecord !== null)
			$params['crontabRecord'] = $crontabRecord;

		$result = $this->getCommand(
			'UpdateOrchestration',
			$params
		)->execute();
		return $result;
	}

	/**
	 * Get orchestration details
	 *
	 * @param int $orchestrationId
	 * @return array
	 */
	public function getOrchestration($orchestrationId)
	{
		$result = $this->getCommand(
			'GetOrchestration',
			array(
				'orchestrationId' => $orchestrationId
			)
		)->execute();
		return $result;
	}

	/**
	 * List orchestration jobs
	 *
	 * @param int $orchestrationId
	 * @return array
	 */
	public function getOrchestrationJobs($orchestrationId)
	{
		$result = $this->getCommand(
			'GetOrchestrationJobs',
			array(
				'orchestrationId' => $orchestrationId
			)
		)->execute();
		return $result;
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

		$command->execute();
		if ($command->getResponse()->getStatusCode() != 204)
			return false;

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
		$result = $this->getCommand(
			'GetJob',
			array(
				'jobId' => $jobId
			)
		)->execute();
		return $result;
	}

	/**
	 * Manualy execute orchestration
	 *
	 * @param int $orchestrationId
	 * @return array
	 */
	public function createJob($orchestrationId)
	{
		$result = $this->getCommand(
			'CreateJob',
			array(
				'orchestrationId' => $orchestrationId
			)
		)->execute();
		return $result;
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

		$command->execute();
		if ($command->getResponse()->getStatusCode() != 204)
			return false;

		return true;
	}
}