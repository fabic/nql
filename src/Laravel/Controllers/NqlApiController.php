<?php

namespace Fabic\Nql\Laravel\Controllers;

use Fabic\Nql\Laravel\Contracts\NqlQueryHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class NqlApiController extends BaseController
{
	/**
	 * @var NqlQueryHandler
	 */
	protected $nql;

	public function __construct(NqlQueryHandler $nql)
	{
		$this->nql = $nql;
	}

    public function queryAction(Request $request)
    {
	    $query = $request->getContent();

	    \Log::debug("Query: $query");

	    $result = $this->nql->handle($query);

        return new Response(
            $result, 200, [
                'Content-Type' => 'application/json'
            ]
        );
    }
}
