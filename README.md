# NQL is not GraphQL, at all.

_Or maybe it is something like “GraphQL for the rest of us” ;-_

__EDIT/2018-06-10/v0.0.1:__ publishing to packagist, even though it's in very early stages of development.

## Use cases

* as an “extended” Symfony Property Path grammar ?.
* as an alternative to GraphQL ?
* at least for rapid prototyping client-side JS.
* ...

## Getting started

```bash
$ composer require fabic/nql
```

## It might work without further configuration

A route for `/api/nql/query` should exists that accepts a query as POST body.

Test with Curl:

```bash
$ curl -D /dev/stderr \
    -H "Content-Type: application/not+graphql" \
    -H "Accept: application/json" \
    -X POST http://localhost/api/nql/query \
    -d '\App\User { id, email, username, created_at, updated_at, roles }
'
```

### Laravel 5.4.x : Declare the service provider.

```php
// config/app.php
return [
    // ...
    'providers' => [
        // ...
        Fabic\Nql\Laravel\NqlServiceProvider::class,
    ]
    // ...
];
```

### Optional: Publish config/nql.php file

```bash
$ ./artisan vendor:publish -v --provider="Fabic\Nql\Laravel\NqlServiceProvider"
```

### Optional: Setup CORS wrt. app that serves subdomains.

```apacheconfig
Header always set Access-Control-Max-Age "1000"
Header always set Access-Control-Allow-Headers "X-Requested-With, Content-Type, Origin, Authorization, Accept, Client-Security-Token, X-Custom-Header"
Header always set "Access-Control-Allow-Methods" "GET, POST, OPTIONS, PUT, DELETE"

#Header always set Access-Control-Allow-Origin "*"
SetEnvIf Origin ^(https?://(?:.+\.)?(example.net|elsewhere.org|whatever.com)(?::\d{1,5})?)$  CORS_ALLOW_ORIGIN=$1
Header append Access-Control-Allow-Origin  %{CORS_ALLOW_ORIGIN}e  env=CORS_ALLOW_ORIGIN

# This one is recommanded too, why? I can't remember.
Header merge  Vary "Origin"
```

#### Access-Control-Allow-Credentials true

And also for XHR/Ajax requests to be received with credentials (i.e. authenticated) :

```apacheconfig
Header append Access-Control-Allow-Credentials true  env=CORS_ALLOW_ORIGIN
```

So that one would for ex. with jQuery :

```javascript
$.ajax({
	type: 'POST',
	url: 'http://hyperion.example.net',
	data: $('form#nql-query').serialize(),
	xhrFields: { withCredentials: true },   //< (!)
	success: function (data) {
		console.log("RESPONSE", data);
	}
});
```


### Laravel API route decl.

By means of a closure, directely in `routes/api.php` :

```php
/* routes/api.php */
Route::post('/dude', function (Request $request) {

    $query = $request->getContent();

    \Log::debug("NQL: Query: $query");

    $parser = new \Fabic\Nql\Parser();

    $entities = $parser->parse($query);

    $result = $parser->apply($entities, [

        'users' => function(array &$meta, PropertyAccessorInterface $pa) {
            if (! empty($meta['identifier'])) {
                $user = \App\User::find($meta['identifier']);
                return $user;
            }
            else {
                $users = \App\User::all($columns);
                return $users;
            }
        },

        'orders' => \App\Order::all(),

        'order:states' => function(array &$meta, PropertyAccessorInterface $pa) {
            $states = \DB::table('orders')->groupBy('state')->get(['state'])->pluck('state');
            return $states;
        }

    ]);

    return $result;
});
```

### Experimenting with it

```bash
$ curl -D /dev/stderr \
    -H "Content-Type: application/not+graphql" \
    -H "Accept: application/json" \
    --cookie "XDEBUG_SESSION=PHPSTORM; path=/; domain=.localdomain;" \
    -X POST http://localhost/api/nql/query \
    -d '
users | randomize | sort: updated_at | limit: 25 {
    id, name, surname, email,
    country {
        id,
        name,
        iso_code
    },
    created_at, updated_at
},
order:states
' \
  | jq -rC '.'
```

## ChangeLog

* 0.0.1 / 2018-06-10 : proof-of-concept impl.

## Thanks & Credits

* Thanks to the folks of `\Doctrine\Common\Annotations` which implementation
  was used as a starting point for the Lexer and Parser classes.


## Notes to self

### Development setup

Dude, you did develop this one thing by creating a subdir. `fabic/nql` for that
library into that fellow project...

```json
/* composer.json of top-level project */
{
    "repositories": [
        {
            "type": "path",
            "url": "fabic/nql",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then:
```bash
$ composer require --dev -vv fabic/nql:dev-master
```

Ensure Composer did symlink it as expected:

```bash
ls -ld vendor/fabic/nql
lrwxrwxrwx 1 fabi fabi 15 Jun 10 10:07 vendor/fabic/nql -> ../../fabic/nql
```

And you may have to run:

```bash
$ composer update -vv fabic/nql
```

once in a while when you change `composer.json`.

__EOF__
