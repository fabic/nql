<?php

namespace Fabic\Nql\Laravel\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

class BaseController extends Controller
{
	public function __construct(Request $request)//, LaravelDebugbar $debugbar)
	{
//		$this->debugbar = $debugbar;

//		if ($request->hasSession()) {
//			$request->session()->reflash();
//		}
	}
}