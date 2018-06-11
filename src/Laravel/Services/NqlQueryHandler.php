<?php

namespace Fabic\Nql\Laravel\Services;

use Fabic\Nql\Laravel\Contracts\DataSource;
use Fabic\Nql\Laravel\Contracts\NqlQueryHandler as NqlQueryHandlerContract;
use Fabic\Nql\Parser;
use Psr\Log\LoggerInterface;

/**
 * Our `nql` service impl.: basically it is registered to be injected
 * `'nql.data.source'` tagged services.
 *
 * Client code would typically invoke the `handle($nqlQuery)` method
 * which will have the query parsed and applied in turn to the declared
 * data sources.
 *
 * fixme: basic impl. needs some thinking here wrt. what happens when a
 * fixme: data source resolves only some part of the given query.
 * fixme: plus we need to stop processing once enough data sources have
 * fixme: been invoked to resolve _all_ of the query top-level identifiers.
 *
 * @since 2018-06-10
 * @author fabic.net
 */
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
			// todo: we need to find out what was resolve, vs what wasn't.
		}

		return $result;
	}
}