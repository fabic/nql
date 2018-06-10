<?php

namespace Fabic\Nql\Exceptions;

class ParserException extends NqlException
{
	/**
	 * Creates a new ParserException describing a Syntax error.
	 *
	 * @param string $message Exception message
	 *
	 * @return self
	 */
	public static function syntaxError($message)
	{
		return new self('[Syntax Error] ' . $message);
	}
}