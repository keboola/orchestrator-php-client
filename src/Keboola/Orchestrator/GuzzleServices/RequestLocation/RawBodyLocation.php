<?php
namespace Keboola\Orchestrator\GuzzleServices\RequestLocation;

use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\Guzzle\Parameter;
use GuzzleHttp\Command\Guzzle\RequestLocation\AbstractLocation;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7;

/**
 * Ads body to request, replace existing data
 */
class RawBodyLocation extends AbstractLocation
{
	private $contentType;

	/**
	 * Set the name of the location
	 *
	 * @param string $locationName
	 */
	public function __construct($locationName = 'rawBody', $contentType = 'application/json')
	{
		parent::__construct($locationName);

		$this->contentType = $contentType;
	}

	/**
	 * @param CommandInterface $command
	 * @param RequestInterface $request
	 * @param Parameter $param
	 *
	 * @return MessageInterface
	 */
	public function visit(
		CommandInterface $command,
		RequestInterface $request,
		Parameter $param
	) {
		$value = $command[$param->getName()];

		// Don't overwrite the Content-Type if one is set
		if ($this->contentType && !$request->hasHeader('Content-Type')) {
			$request = $request->withHeader('Content-Type', $this->contentType);
		}

		return $request->withBody(Psr7\stream_for($param->filter($value)));
	}
}

