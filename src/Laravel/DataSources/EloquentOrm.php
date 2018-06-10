<?php

namespace Fabic\Nql\Laravel\DataSources;

use Fabic\Nql\Parser;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Psr\Log\LoggerInterface;

/**
 * Basic impl. of a data source that accepts FQCN of ORM entities,
 * e.g. `\App\User`, (btw. which extend `\Illuminate\Database\Eloquent\Model`),
 * and :
 * - either fetches one: Query `\App\User#123` -means-> `\App\User::find(123)`
 * - or _all_: Query `\App\User` -means-> `\App\User::all()`
 *
 * @since 2018-06-10
 * @author fabic.net
 */
class EloquentOrm extends AbstractDataSource
{
	/**
	 * @var Parser
	 */
	protected $nqlQueryParser;

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * EloquentOrm constructor.
	 *
	 * @param DatabaseManager $dbm unused
	 * @param Parser          $nqlQueryParser
	 * @param LoggerInterface $logger
	 */
	public function __construct(DatabaseManager $dbm, Parser $nqlQueryParser, LoggerInterface $logger)
	{
		$this->nqlQueryParser = $nqlQueryParser;
		$this->logger = $logger;
	}

	public function apply(array $entities)
	{
		$root = function($ppath, $meta) {
			if (! class_exists($ppath))
				return null;
			else if (! is_subclass_of($ppath, Model::class))
				return null;

			$className = $ppath;

			if (!empty($meta['identifier'])) {
				$entity = call_user_func([$className, 'find'], $meta['identifier']);
				return $entity;
			}
			else {
				$this->logger->debug("Nql: DataSource\\EloquentOrm: Fetching all entities of type '$className'.");

				// FALSE: if we filter the set of retrieved columns here, then the
				//        data mapper impl. of ours (Parser::apply() method) may operate
				//        on data that isn't there. todo: decide what to do.
				//        + we're now accepting those “properties” as any form of property path
				//          'tis maybe better not to filter things here, in the end.
				$columns = FALSE &&
					!empty($meta['properties']) ? array_map(function (array $props) {
						return reset($props);
					}, $meta['properties']) : ['*'];

				// TODO: we _may_ filter thing here using $meta['where'] ;
				// TODO: and also $meta['sort']
				// TODO: and also $meta['limit']
				// TODO: or not, if it's too much trouble...

				/** @var Collection $entities */
				$entities = call_user_func([$className, 'all'], $columns);

				$this->logger->debug(sprintf("Nql: DataSource\\EloquentOrm: Fetched %u of '%s'.",
					$entities->count(), $className));

				return $entities;
			}
		};

		$result = $this->nqlQueryParser->apply($entities, $root);

		return $result;
	}
}