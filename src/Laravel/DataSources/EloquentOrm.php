<?php

namespace Fabic\Nql\Laravel\DataSources;

use Fabic\Nql\Parser;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

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
		$root = [
			'users' => function (array &$meta, PropertyAccessorInterface $pa) {
				// $columns = !empty($meta['properties']) ? array_keys($meta['properties']) : ['*'];
				$columns = FALSE && !empty($meta['properties']) ? array_map(function (array $props) {
					return reset($props);
				}, $meta['properties']) : ['*'];
				// FIXME: if user requests 'country' which is actually an FK 'country_id'...
				if (!empty($meta['identifier'])) {
					$user = \App\User::find($meta['identifier']);
					return $user;
				} else {
					$this->logger->debug("Nql: DataSource\\EloquentOrm: Fetching all users.");
					$users = \App\User::all($columns);
					return $users;
				}
			},

		];

		$result = $this->nqlQueryParser->apply($entities, $root);

		return $result;
	}
}