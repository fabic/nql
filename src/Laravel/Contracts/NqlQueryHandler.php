<?php

namespace Fabic\Nql\Laravel\Contracts;


interface NqlQueryHandler
{
	public function handle($query);
}