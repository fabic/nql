<?php

namespace Fabic\Nql\Laravel\DataSources;

use Fabic\Nql\Laravel\Contracts\DataSource;

/**
 * this may never serve anything :-/
 */
abstract class AbstractDataSource implements DataSource
{
	public function __construct()
	{
		/* noop */
	}
}