# NQL is not GraphQL, at all.

_Or maybe it is something like “GraphQL for the rest of us” ;-_


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
