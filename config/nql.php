<?php

return [

	/*
	|--------------------------------------------------------------------------
	| The logging service
	|--------------------------------------------------------------------------
	|
	| This option defines the default log channel that gets used when writing
	| messages to the logs. The name specified in this option should match
	| one of the channels defined in the "channels" configuration array.
	|
	*/

	'logger' => 'log',


	/*
	 |--------------------------------------------------------------------------
	 | NQL route prefix
	 |--------------------------------------------------------------------------
	 |
	 | Sometimes you want to set route prefix to be used by NQL to load
	 | its resources from.
	 |
	 */
	'route_prefix' => 'nql',

	/*
	 |--------------------------------------------------------------------------
	 | NQL route domain
	 |--------------------------------------------------------------------------
	 |
	 | By default NQL route served from the same domain that request served.
	 | To override default domain, specify it as a non-empty value.
	 */
	'route_domain' => env('API_URL', null) ? parse_url(env('API_URL'), PHP_URL_HOST) : null,

	/*
	 |--------------------------------------------------------------------------
	 | NQL route middleware
	 |--------------------------------------------------------------------------
	 |
	 */

	'route_middleware' => [],
];
