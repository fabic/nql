<?php

namespace Fabic\Nql\Laravel;

use Fabic\Nql\Parser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Our Laravel service provider.
 *
 * For Laravel 5.4, edit your `config/app.php` and add this class FQCN to the 'providers' key.
 *
 * @since 2018-06-10
 * @author fabic.net
 */
class NqlServiceProvider extends ServiceProvider
{
	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = true;

	/**
	 * NqlServiceProvider constructor.
	 *
	 * todo: remove this, here for debugging purposes.
	 *
	 * @param Application $app
	 */
	public function __construct(Application $app)
	{
		parent::__construct($app);
	}

	/**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
	    $this->publishes([
		    __DIR__.'/../../config/nql.php' => config_path('nql.php'),
	    ], 'config');

	    if ($this->app->runningInConsole()) {
		    $this->commands([
		    	Commands\DummyNqlCommand::class
		    ]);
	     }

	    // Declare out query API endpoints.
	    $routeConfig = [
		    'namespace' => 'Fabic\Nql\Laravel\Controllers',
		    'prefix' => $this->getAppConfig()->get('nql.route_prefix'),
		    'domain' => $this->getAppConfig()->get('nql.route_domain'),
		    'middleware' => $this->getAppConfig()->get('nql.route_middleware'), //[DebugbarEnabled::class],
	    ];

	    $this->getRouter()->group($routeConfig, function(Router $router) {
		    $router->post('query', [
			    'uses' => 'NqlApiController@queryAction',
			    'as' => 'nql.api.query',
		    ]);

		    // stub: todo: impl. someday.
		    $router->get('query/schema', [
			    'uses' => 'NqlApiController@schemaAction',
			    'as' => 'nql.api.query.schema',
		    ]);
	    });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
	    $this->mergeConfigFrom(
		    __DIR__.'/../../config/nql.php',
		    'nql'
	    );

	    $this->app->singleton(Parser::class,
		    function (Application $app) {
			    $logger = $app->make($this->getAppConfig()->get('nql.logger'));
			    return new Parser($logger);
		    });

	    $this->app->alias(Parser::class, 'nql.query.parser');

	    // todo: find out if this has any advantage at all.
	    $this->app->bind(
		    Contracts\NqlQueryHandler::class,
		    Services\NqlQueryHandler::class
	    );

	    $this->app->singleton(
		    Services\NqlQueryHandler::class,
		    function (Application $app) {
			    $nqlParser = $app->make('nql.query.parser');
			    $dataSources = $app->tagged('nql.data.source');
			    $logger = $app->make( $this->getAppConfig()->get('nql.logger') );
			    return new Services\NqlQueryHandler($nqlParser, $dataSources, $logger);
		    });

	    $this->app->alias(Services\NqlQueryHandler::class, 'nql');

	    // ~~~ register our default data sources ~~~
	    // todo: have a config. option for disabling this toy/demonstration impl.

	    $this->app->bind(
		    DataSources\EloquentOrm::class,
		    function (Application $app) {
		    	/** @var \Illuminate\Database\DatabaseManager $db */
		    	$db = $app->make('db'); // fixme: actually unused.
			    $nqlParser = $app->make('nql.query.parser');
			    $logger = $app->make( $this->getAppConfig()->get('nql.logger') );
			    return new DataSources\EloquentOrm($db, $nqlParser, $logger);
		    });

	    $this->app->tag([DataSources\EloquentOrm::class], ['nql.data.source']);

    }


	/* *
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
//
// it appears we don't need to do this to have our
// services be declared/discovered.
//
//	public function provides()
//	{
//		return [
//			'nql.query.parser',
//			'nql',
//			// Services\NqlQueryHandler::class,
//			// Parser::class
//		];
//	}

	/**
	 * Helper for having type information about `$this->app['config']`.
	 *
	 * @return \Illuminate\Config\Repository
	 */
	protected function getAppConfig()
	{
		return $this->app['config'];
	}

	/**
	 * Get the active router.
	 *
	 * @return Router
	 */
	protected function getRouter()
	{
		return $this->app['router'];
	}

	// todo: impl.!
	protected function registerBladeExtensions()
	{
		$this->app->afterResolving('blade.compiler', function (BladeCompiler $bladeCompiler) {
			$bladeCompiler->directive('nql', function ($arguments) {
				list($role, $guard) = explode(',', $arguments.',');

				return "<?php if(auth({$guard})->check() && auth({$guard})->user()->hasRole({$role})): ?>";
			});
			$bladeCompiler->directive('endnql', function () {
				return '<?php endif; ?>';
			});
		});
	}

}
