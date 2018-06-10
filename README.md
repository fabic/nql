# NQL is not GraphQL, at all.

_Or maybe it is something like “GraphQL for the rest of us” ;-_

## Getting started

### CORS

```apacheconfig
Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Max-Age "1000"
Header always set Access-Control-Allow-Headers "X-Requested-With, Content-Type, Origin, Authorization, Accept, Client-Security-Token, X-Custom-Header"
Header always set "Access-Control-Allow-Methods" "GET, POST, OPTIONS, PUT, DELETE"
```

### Laravel API route decl.

```php
/* routes/api.php */
Route::post('/dude', function (Request $request, ContainerInterface $container) {
    if (!$container instanceof \Illuminate\Foundation\Application)
        throw new \InvalidArgumentException("Dude! this can't be!");

    $query = $request->getContent();

    \Log::debug("NQL: Query: $query");

    $parser = new \Fabic\Nql\Parser();

    $entities = $parser->parse($query);

    $result = $parser->apply($entities, [
        // 'users' => \App\User::all(),

        // Or:
        'users' => function(array &$meta, PropertyAccessorInterface $pa) {
            // $columns = !empty($meta['properties']) ? array_keys($meta['properties']) : ['*'];
            $columns = FALSE && !empty($meta['properties']) ? array_map(function(array $props) { return reset($props); }, $meta['properties']) : ['*'];
            // FIXME: if user requests 'country' which is actually an FK 'country_id'...
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
    --cookie "XDEBUG_SESSION=PHPSTORM; path=/; domain=.gosoko.local;" \
    -X POST http://api.gosoko.local/api/dude \
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

## Note to self

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
