<?php

namespace Fabic\Nql\Laravel\Services;

use Fabic\Nql\Laravel\Contracts\DataSource;
use Fabic\Nql\Laravel\Contracts\NqlQueryHandler as NqlQueryHandlerContract;
use Fabic\Nql\Parser;
use Psr\Log\LoggerInterface;

class NqlQueryHandler implements NqlQueryHandlerContract
{
	/**
	 * @var DataSource[]
	 */
	protected $dataSources = [];

	/**
	 * @var Parser
	 */
	protected $nqlQueryParser;

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * NqlQueryHandler constructor.
	 *
	 * @param Parser          $nqlQueryParser
	 * @param DataSource[]    $dataSources
	 * @param LoggerInterface $logger
	 */
	public function __construct(Parser $nqlQueryParser, array $dataSources, LoggerInterface $logger)
	{
		$this->nqlQueryParser = $nqlQueryParser;
		if (!empty($dataSources))
			array_push($this->dataSources, ...$dataSources);
		$this->logger= $logger;
	}

	/**
	 *
	 * @param string $query
	 * @return array
	 * @throws \Fabic\Nql\Exceptions\ParserException
	 */
	public function handle($query)
	{
		$this->logger->debug("Nql: Handling query:", ['query' => $query]);

		$entities = $this->nqlQueryParser->parse($query);
		$result = [];

		foreach($this->dataSources as $dataSource)
		{
			$this->logger->debug(sprintf("Nql: Applying query to data source '%s'", get_class($dataSource)));
			$res = $dataSource->apply( $entities );
			$result = array_merge_recursive($result, $res);
			// fixme: ^ array_merge_recursive() may not be the good approach here.
		}

		return $result;
	}
}