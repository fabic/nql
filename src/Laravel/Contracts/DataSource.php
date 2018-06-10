<?php

namespace Fabic\Nql\Laravel\Contracts;


interface DataSource
{
	public function apply(array $entities);

}