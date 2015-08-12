<?php
namespace Keboola\Orchestrator;

class OrchestrationTask
{
	private $component;

	private $componentUrl;

	private $action;

	private $actionParameters = array();

	private $continueOnFailure = false;

	private $active = true;

	private $timeoutMinutes;

	private $phase;

	public function __construct()
	{

	}

	/**
	 * Set component name
	 *
	 * List of available components http://docs.keboola.apiary.io/#get-%2Fv2%2Fstorage%2Fcomponents
	 *
	 * @param string $value
	 * @return $this
	 */
	public function setComponent($value)
	{
		$this->component = (string) $value;
		return $this;
	}

	/**
	 * Get component name
	 *
	 * @return null|string
	 */
	public function getComponent()
	{
		return $this->component;
	}

	/**
	 * Set component url
	 *
	 * @param string $value
	 * @return $this
	 */
	public function setComponentUrl($value)
	{
		$this->componentUrl = (string) $value;
		return $this;
	}

	/**
	 * Get component url
	 *
	 * @return null|string
	 */
	public function getComponentUrl()
	{
		return $this->componentUrl;
	}

	/**
	 * Set component action to run
	 *
	 * @param string $value
	 * @return $this
	 */
	public function setAction($value)
	{
		$this->action = (string) $value;
		return $this;
	}

	/**
	 * Get component action to run
	 *
	 * @return null|string
	 */
	public function getAction()
	{
		return $this->action;
	}

	/**
	 * Set action parameters
	 *
	 * Specify action parameters as associative array
	 *
	 * @param array $value
	 * @return $this
	 */
	public function setActionParameters(array $value)
	{
		$this->actionParameters = $value;
		return $this;
	}

	/**
	 * Get action parameters
	 *
	 * @return mixed
	 */
	public function getActionParameters()
	{
		return $this->actionParameters;
	}

	/**
	 * Set continue on failure
	 *
	 * @param bool $value
	 * @return $this
	 */
	public function setContinueOnFailure($value = true)
	{
		$this->continueOnFailure = (bool) $value;
		return $this;
	}

	/**
	 * Get continue on failure
	 *
	 * @return bool
	 */
	public function getContinueOnFailure()
	{
		return $this->continueOnFailure;
	}

	/**
	 * Set active
	 *
	 * @param bool $value
	 * @return $this
	 */
	public function setActive($value = true)
	{
		$this->active = (bool) $value;
		return $this;
	}

	/**
	 * Get active
	 *
	 * @return bool
	 */
	public function getActive()
	{
		return $this->active;
	}

	/**
	 * Set task timeout in minutes
	 *
	 * @param int $value
	 * @return $this
	 */
	public function setTimeoutMinutes($value)
	{
		if ($value)
			$this->timeoutMinutes = (int) $value;
		else
			$this->timeoutMinutes = null;
		return $this;
	}

	/**
	 * Get task timeout in minutes
	 *
	 * @return null|int
	 */
	public function getTimeoutMinutes()
	{
		return $this->timeoutMinutes;
	}

	/**
	 * Set task phase
	 *
	 * @param int $value
	 * @return $this
	 */
	public function setPhase($value)
	{
		$value = (int) $value;
		if ($value)
			$this->phase = $value;
		else
			$this->phase = null;

		return $this;
	}

	/**
	 * Get task phase
	 *
	 * @return null|int
	 */
	public function getPhase()
	{
		return $this->phase;
	}

	/**
	 * @return array
	 */
	public function toArray()
	{
		return array(
			'component' => $this->getComponent(),
			'componentUrl' => $this->getComponentUrl(),
			'action' => $this->getAction(),
			'actionParameters' => $this->getActionParameters(),
			'continueOnFailure' => $this->getContinueOnFailure(),
			'active' => $this->getActive(),
			'timeoutMinutes' => $this->getTimeoutMinutes(),
			'phase' => $this->getPhase(),
		);
	}
}