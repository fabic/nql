<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Fabic\Nql;

use Doctrine\Common\Annotations\AnnotationException; // todo: have our own exception cls.
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 *
 * ### Ideas
 *
 * * Have a subclass that would impl. some basic automatic caching ?
 *   (though this would possibly pose some security concerns, possibly).
 *      - ex. we may hash queries and store the final data after processing.
 *      - __or__ we may just cache the "root" raw values fetched through __apply()__,
 *        so as to e.g. avoid multiple database/ORM queries.
 *
 * * TODO: Remove those methods of the original `DocParser` impl. once we know we won't need these.
 *
 * @since 2018-05-29
 * @author Fabien Cadet <cadet.fabien@gmail.com>
 *
 * @see \Doctrine\Common\Annotations\DocParser original stating point impl.
 */
class Parser
{
    /**
     * An array of all valid tokens for a class name.
     *
     * @var array
     */
    private static $classIdentifiers = array(
        Lexer::T_IDENTIFIER,
        Lexer::T_TRUE,
        Lexer::T_FALSE,
        Lexer::T_NULL,
	    Lexer::T_COLON,
    );

    /**
     * The lexer.
     *
     * @var Lexer
     */
    private $lexer;

	/**
	 * @var LoggerInterface
	 */
    protected $logger;

	/**
	 * Constructs a new NQL Parser.
	 *
	 * @param LoggerInterface $logger
	 */
    public function __construct(LoggerInterface $logger)
    {
        $this->lexer = new Lexer();
        $this->logger = $logger;
    }

	/**
	 * Parses the given “NQL ”query.
	 *
	 * @param string $input The NQL query string to parse.
	 *
	 * @return array
	 *
	 * @throws Exceptions\ParserException
	 */
    public function parse($input)
    {
        $this->lexer->setInput($input);
        $this->lexer->moveNext();

	    $entities = $this->Entities();

	    return $entities;
    }

	/**
	 * Apply a (parsed) query to some `$root` value.
	 *
	 * Note: recursive it is.
	 *
	 * TODO: SRP: move this apply() and related methods to some dedicated class,
	 * TODO: SRP: once we can come up with a name for it, like Nql maybe?
	 *
	 * @param array $entities that which `parse()` yields.
	 * @param mixed $root     the “root” data to be operated upon; may be
	 *   a callable that takes arguments: ($ppath, $meta), i.e. the current
	 *   property path and metadata we're operating upon.
	 *
	 * @param PropertyAccessorInterface|null $pa
	 *
	 * @return array
	 */
	public function apply(array $entities, $root, PropertyAccessorInterface $pa = null)
	{
		$pa = $pa ?: PropertyAccess::createPropertyAccessorBuilder()
						->enableMagicCall()
						->getPropertyAccessor();

		$retv = [];

		foreach($entities as $alias => $meta)
		{
			$ppath = $meta['ppath'];

			// todo: identifier may be a property path.

			// $root may be a callable for cases where the callee does not know
			// in advance if it knows about that $ppath.
			if (is_callable($root)) {
				$thing = call_user_func($root, $ppath, $meta);
			}
			// Symf's ppath component wants arrays to be accessed as `[$ppath]`.
			else if (is_array($root) || $root instanceof \ArrayAccess)
				$thing = $pa->getValue($root, "[$ppath]");
            // We need to filter out those things we can't traverse.
            else if (is_scalar($root) || is_null($root)) {
            	$this->logger->warning("Nql: Got a nil \$root of type " . gettype($root));
	            return null;
            }
            // before we let the ppath component do it's best to traverse $root.
			else {
				$thing = $pa->getValue($root, $ppath);
			}

			// And $thing _may also_ be a callable for the ability for
			// client code to impl. some form of lazy evaluation.
			if (is_callable($thing)) {
				$thing = $thing($meta, $pa);
			}

			// Laravel's Eloquent/ORM relation
			// todo: if class_exists()... since Laravel is optional.
			if ($thing instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
				$thing = $thing->get();
			}
			else if ($thing instanceof \DateTime) {
				$thing = $thing->format('c');
			}

			// “Transform traversable non-arrays”
			// There are "good" reasons not to perform this upfront:
			// we may want to keep the actual type of that "thing" for
			// as long as we can so that we can extract properties that
			// may be filtered by whatever impl. of \ArrayAccess or \Traversable
			// we're working with.
			if (false) {
				if (!is_array($thing) && $thing instanceof \Traversable) {
					$thing = iterator_to_array($thing);
				}
			}

			// Recurs. apply sub-query <=> we have more property paths
			// to apply to $thing.
			if (! empty($meta['properties']))
			{
				if (is_array($thing) || $thing instanceof \Traversable) {
					$_thing = [];
					foreach ($thing as $_key => $_elt) {
						$_thing[ $_key ] = $this->apply($meta['properties'], $_elt, $pa);
					}
					$thing = $_thing;
					unset($_thing, $_key, $_elt);
				}
				else {
					$thing = $this->apply($meta['properties'], $thing, $pa);
				}
			}

			// Indeed this happens a lot, obviously.
			if (false) {
				if ($thing instanceof \ArrayAccess && !($thing instanceof \Traversable)) {
					\Log::notice("Beware! that \$thing we're working with implements \\ArrayAccess"
						. " but _not_ \\Traversable, is-a: " . get_class($thing));
				}
			}

			// 'tis practical for us to perform this now.
			if (!is_array($thing) && $thing instanceof \Traversable) {
				$thing = iterator_to_array($thing);
			}

			// ~~ Proceed with those modifiers (randomize, sort, limit, where, etc.) ~~
			// todo: index, where, etc.

			foreach($meta['modifiers'] as [$modifier, $params])
			{
				switch ($modifier) // ugly switch-case that is.
				{
					case 'randomize':
						if (!is_array($thing)) {
							throw new \InvalidArgumentException(
								"Randomize: Ouch! that \"thing\" should be an array by now,"
								. " got: " . gettype($thing));
						}
						shuffle($thing);
						\Log::debug("Nql: Parser::apply: Randomized \$thing.");
						break;
					// fixme: this is certainly inefficient, but we may not do anything about that right now.
					case 'sort': {
						if (!is_array($thing)) {
							throw new \InvalidArgumentException(
								"Sort: Ouch! that \"thing\" should be an array by now,"
								. " got: " . get_class($thing));
						}
						usort($thing, function ($left, $right) use ($params, $pa) {
							foreach ($params as $prop => $dir) {
								$l = $pa->getValue($left, "[$prop]");
								$r = $pa->getValue($right, "[$prop]");
								$e = ($l < $r ? -1 : ($l > $r ? 1 : (0)));
								if ($e != 0)
									return $dir == true ? $e : -$e;
							}
							return 0;
						});
						\Log::debug("Nql: Parser::apply: Sorted \$thing.");
						break;
					}
					case 'limit':
						$thing = array_slice($thing, $params[0], $params[1]);
						break;
					default:
						\Log::warning("Nql: parser::apply : unknown modifier '$modifier', payload: '$params'.");
				}
			} // modifiers iter. //
			unset($modifier, $params);

			$retv[ $alias ] = $thing;
		}

		return $retv;
    }

	/**
	 * Entities ::= Entity ["," ...]
	 *
	 * @return array
	 * @throws Exceptions\ParserException
	 */
	private function Entities()
	{
		$entities = [];

		while (null !== $this->lexer->lookahead)
		{
			$entity = $this->Entity();

			[$alias, $meta] = $entity;

			if (array_key_exists($alias, $entities))
				throw new \InvalidArgumentException("Dude: duplicate name '$alias', already exists.");
			$entities[ $alias ] = $meta;

			// $this->lexer->moveNext();

			$lookahead = $this->lexer->lookahead;

			if ($lookahead['type'] != Lexer::T_COMMA) {
				\Log::debug("Entities :: loop :: break.");
				break;
			}

			$this->match(Lexer::T_COMMA);
		}

		return $entities;
	}

	/**
	 * Entity ::= identifier ["#"(identifier|number)] ["|" (sort|where|filter|map) ":" <tbd> ] "{" Entities "}"
	 *
	 * todo: impl Identifier() to parse any valid property access string.
	 *
	 * @throws Exceptions\ParserException
	 */
	private function Entity()
	{
		$this->match(Lexer::T_IDENTIFIER);
		$name = $this->Identifier();

		$meta = [
			'ppath'      => $name,  // a property path ?
			'properties' => null,
			'identifier' => null,   // #1234 // #abcdef // #abc-def-123 ?
			'modifiers'  => [
			]
		];

		// "#"<number>
		if ($this->lexer->lookahead['type'] == Lexer::T_SHARP) {
			$this->match(Lexer::T_SHARP);
			$this->match(Lexer::T_INTEGER);  // todo: impl. matching some specific lexer type like T_GENERIC_IDENTIFIER ?
											 // todo: that would accept numbers, and strings like "some-abc-123" maybe.
			$meta['identifier'] = $this->lexer->token['value'];
		}

		// "as" <identifier>
		if ($this->lexer->lookahead['type'] == Lexer::T_AS) {
			$this->match(Lexer::T_AS);
			$this->match(Lexer::T_IDENTIFIER);
			$name = $this->lexer->token['value'];
		}

		// Modifiers :
		// sort
		// where         //  where: id > 100         //  where: name is not null, description is null
		//               //  where: id in (1,2,3,4)  //  where: has(comments).
		// filter  ?
		// map     ?
		// first        // first // first: 10
		// last         // last  //  last: 10
		// any
		// count
		// limit
		// randomize

		while ($this->lexer->lookahead['type'] == Lexer::T_PIPE)
		{
			$this->match(Lexer::T_PIPE);
			switch ($this->lexer->lookahead['value'])
			{
				// sort: created_at desc, name asc
				case 'sort':
					$meta['modifiers'][] = ['sort', $this->SortModifier()];
					break;
				// limit: 10 // limit: 2, 10
				case 'limit':
					$meta['modifiers'][] = ['limit', $this->LimitModifier()];
					break;
				case 'randomize':
					$this->match(Lexer::T_IDENTIFIER);
					$meta['modifiers'][] = ['randomize', true];
					break;
				// TODO: impl. these other modifiers.
				// Default to parsing a “generic modifier” that is either:
				// - a single keyword, like ex. 'randomize'
				// - or `keyword: anything but the pipe character`.
				default:
					$meta['modifiers'][] = $this->unknownModifier();
			}
		}

		// Recurs. parse sub-query spec...
		if ($this->lexer->lookahead['type'] == Lexer::T_OPEN_CURLY_BRACES) {
			$this->match(Lexer::T_OPEN_CURLY_BRACES);
			$meta['properties'] = $this->Entities();
			$this->match(Lexer::T_CLOSE_CURLY_BRACES);
		}

		return [ $name, array_filter($meta, function($v) { return $v !== null; }) ];
	}

	/**
	 * Sort ::= "sort:" <identifier> ["asc"|"desc"|<identifier>] [, ...]
	 *
	 * @return array
	 * @throws Exceptions\ParserException
	 */
	private function SortModifier()
	{
		$this->match(Lexer::T_IDENTIFIER);
		$this->match(Lexer::T_COLON);

		$terms = [];

		while(null !== $this->lexer->lookahead) {
			$this->match(Lexer::T_IDENTIFIER);
			$name = $this->lexer->token['value'];
			$modifier = true;

			// asc / desc
			if ($this->lexer->lookahead['type'] == Lexer::T_IDENTIFIER) {
				$this->match(Lexer::T_IDENTIFIER);
				$modifier = $this->lexer->token['value'];
				$modifier = $modifier == 'asc' ? true : ($modifier == 'desc' ? false : ($modifier));
				// note: ^ we're accepting modifier to be sthg else than 'asc' or 'desc' here.
			}

			$terms[ $name ] = $modifier;

			if ($this->lexer->lookahead['type'] != Lexer::T_COMMA)
				break;
			$this->match(Lexer::T_COMMA);
		}

		return $terms;
	}

	/**
	 * Limit ::= "limit:" integer[,(integer|null)]
	 *
	 * @return array
	 * @throws Exceptions\ParserException
	 */
	private function LimitModifier()
	{
		$this->match(Lexer::T_IDENTIFIER);
		$this->match(Lexer::T_COLON);

		$this->match(Lexer::T_INTEGER);

		[$first, $count] = [0, $this->lexer->token['value']];

		if ($this->lexer->lookahead['type'] == Lexer::T_COMMA) {
			$this->match(Lexer::T_COMMA);
			$first = $count;
			if ($this->lexer->lookahead['type'] == Lexer::T_INTEGER) {
				$this->match(Lexer::T_INTEGER);
				$count = $this->lexer->token['value'];
			}
			else if ($this->lexer->lookahead['type'] == Lexer::T_NULL) {
				$count = null;
			}
			else throw new \InvalidArgumentException("LimitModifier: expected an integer or null !");
		}

		return [$first, $count];
	}

	/**
	 * Unknown ::= identifier [':' (<anything_but_pipe>)* ]
	 *
	 * @return array [keyword, payload].
	 *
	 * @throws Exceptions\ParserException
	 */
	private function unknownModifier()
	{
		$this->match(Lexer::T_IDENTIFIER);
		$keyword = $this->lexer->token['value'];

		$payload = null;

		if ($this->lexer->lookahead['type'] == Lexer::T_COLON) {
			$this->match(Lexer::T_COLON);
			$fragments = [];
			while ($this->lexer->lookahead !== null
				&& $this->lexer->lookahead['type'] !== Lexer::T_PIPE)
			{
				$this->lexer->moveNext();
				$fragment = $this->lexer->token['value'];
				array_push($fragments, $fragment);
				// ^ we do this in this way since Doctrine's Lexer won't allow
				//   us to access the input string, nor extract a slice of it.
			}
			$payload = implode(' ', $fragments);
		}

		return [$keyword, $payload];
	}


	//==-------------------------------------------------------------------==//
	//==-- Support routines, most of which come from Doctrine's          --==//
	//==-- annotations parser                                            --==//
	//==-------------------------------------------------------------------==//


	/**
	 * Attempts to match the given token with the current lookahead token.
	 * If they match, updates the lookahead token; otherwise raises a syntax error.
	 *
	 * @param integer $token Type of token.
	 *
	 * @return boolean True if tokens match; false otherwise.
	 * @throws Exceptions\ParserException
	 */
    private function match($token)
    {
        if ( ! $this->lexer->isNextToken($token) ) {
            $this->syntaxError($this->lexer->getLiteral($token));
        }

        return $this->lexer->moveNext();
    }

	/**
	 * Attempts to match the current lookahead token with any of the given tokens.
	 *
	 * If any of them matches, this method updates the lookahead token; otherwise
	 * a syntax error is raised.
	 *
	 * @param array $tokens
	 *
	 * @return boolean
	 * @throws Exceptions\ParserException
	 */
    private function matchAny(array $tokens)
    {
        if ( ! $this->lexer->isNextTokenAny($tokens)) {
            $this->syntaxError(implode(' or ', array_map(array($this->lexer, 'getLiteral'), $tokens)));
        }

        return $this->lexer->moveNext();
    }

    /**
     * Generates a new syntax error.
     *
     * @param string     $expected Expected string.
     * @param array|null $token    Optional token.
     *
     * @return void
     *
     * @throws Exceptions\ParserException
     */
    private function syntaxError($expected, $token = null)
    {
        if ($token === null) {
            $token = $this->lexer->lookahead;
        }

        $message  = sprintf('Expected %s, got ', $expected);
        $message .= ($this->lexer->lookahead === null)
            ? 'end of string'
            : sprintf("'%s' at position %s", $token['value'], $token['position']);

        if (strlen($this->context)) {
            $message .= ' in ' . $this->context;
        }

        $message .= '.';

        throw Exceptions\ParserException::syntaxError($message);
    }


	/**
	 * Identifier ::= string
	 *
	 * TODO: review impl., simplify for our needs.
	 *
	 * @return string
	 * @throws Exceptions\ParserException
	 *
	 * @author Doctrine folks.
	 */
	private function Identifier()
	{
		// ~~check if we have an annotation~~
		if ($this->lexer->isNextTokenAny(self::$classIdentifiers)) {
			$this->lexer->moveNext();

			$className = $this->lexer->token['value'];

			while ($this->lexer->lookahead['position'] === ($this->lexer->token['position'] + strlen($this->lexer->token['value']))
				&& $this->lexer->isNextToken(Lexer::T_NAMESPACE_SEPARATOR)) {

				$this->match(Lexer::T_NAMESPACE_SEPARATOR);
				$this->matchAny(self::$classIdentifiers);

				$className .= '\\' . $this->lexer->token['value'];
			}

			return $className;
		}

		$identifier = $this->lexer->token['value'];
		return $identifier;
	}

	// ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~
	// ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~
	// ~~  \Doctrine\Common\Annotations\DocParserDoctrine ~ ~ ~~ ~ ~~ ~ ~~ ~ ~~
	// ~~  TODO: REMOVE THESE ONCE WE NO LONGER NEED INSPIRATION.  ~~ ~ ~~ ~ ~~
	// ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~
	// ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~ ~ ~~

	/**
     * Attempts to check if a class exists or not. This never goes through the PHP autoloading mechanism
     * but uses the {@link AnnotationRegistry} to load classes.
     *
     * @param string $fqcn
     *
     * @return boolean
     */
    private function classExists($fqcn)
    {
        if (isset($this->classExists[$fqcn])) {
            return $this->classExists[$fqcn];
        }

        // first check if the class already exists, maybe loaded through another AnnotationReader
        if (class_exists($fqcn, false)) {
            return $this->classExists[$fqcn] = true;
        }

        // final check, does this class exist?
        return $this->classExists[$fqcn] = AnnotationRegistry::loadAnnotationClass($fqcn);
    }

	/**
	 * Collects parsing metadata for a given annotation class
	 *
	 * @param string $name The annotation name
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
    private function collectAnnotationMetadata($name)
    {
        if (self::$metadataParser === null) {
            self::$metadataParser = new self();

            self::$metadataParser->setIgnoreNotImportedAnnotations(true);
            self::$metadataParser->setIgnoredAnnotationNames($this->ignoredAnnotationNames);
            self::$metadataParser->setImports(array(
                'enum'          => 'Doctrine\Common\Annotations\Annotation\Enum',
                'target'        => 'Doctrine\Common\Annotations\Annotation\Target',
                'attribute'     => 'Doctrine\Common\Annotations\Annotation\Attribute',
                'attributes'    => 'Doctrine\Common\Annotations\Annotation\Attributes'
            ));

            AnnotationRegistry::registerFile(__DIR__ . '/Annotation/Enum.php');
            AnnotationRegistry::registerFile(__DIR__ . '/Annotation/Target.php');
            AnnotationRegistry::registerFile(__DIR__ . '/Annotation/Attribute.php');
            AnnotationRegistry::registerFile(__DIR__ . '/Annotation/Attributes.php');
        }

        $class      = new \ReflectionClass($name);
        $docComment = $class->getDocComment();

        // Sets default values for annotation metadata
        $metadata = array(
            'default_property' => null,
            'has_constructor'  => (null !== $constructor = $class->getConstructor()) && $constructor->getNumberOfParameters() > 0,
            'properties'       => array(),
            'property_types'   => array(),
            'attribute_types'  => array(),
            'targets_literal'  => null,
            'targets'          => Target::TARGET_ALL,
            'is_annotation'    => false !== strpos($docComment, '@Annotation'),
        );

        // verify that the class is really meant to be an annotation
        if ($metadata['is_annotation']) {
            self::$metadataParser->setTarget(Target::TARGET_CLASS);

            foreach (self::$metadataParser->parse($docComment, 'class @' . $name) as $annotation) {
                if ($annotation instanceof Target) {
                    $metadata['targets']         = $annotation->targets;
                    $metadata['targets_literal'] = $annotation->literal;

                    continue;
                }

                if ($annotation instanceof Attributes) {
                    foreach ($annotation->value as $attribute) {
                        $this->collectAttributeTypeMetadata($metadata, $attribute);
                    }
                }
            }

            // if not has a constructor will inject values into public properties
            if (false === $metadata['has_constructor']) {
                // collect all public properties
                foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                    $metadata['properties'][$property->name] = $property->name;

                    if (false === ($propertyComment = $property->getDocComment())) {
                        continue;
                    }

                    $attribute = new Attribute();

                    $attribute->required = (false !== strpos($propertyComment, '@Required'));
                    $attribute->name     = $property->name;
                    $attribute->type     = (false !== strpos($propertyComment, '@var') && preg_match('/@var\s+([^\s]+)/',$propertyComment, $matches))
                        ? $matches[1]
                        : 'mixed';

                    $this->collectAttributeTypeMetadata($metadata, $attribute);

                    // checks if the property has @Enum
                    if (false !== strpos($propertyComment, '@Enum')) {
                        $context = 'property ' . $class->name . "::\$" . $property->name;

                        self::$metadataParser->setTarget(Target::TARGET_PROPERTY);

                        foreach (self::$metadataParser->parse($propertyComment, $context) as $annotation) {
                            if ( ! $annotation instanceof Enum) {
                                continue;
                            }

                            $metadata['enum'][$property->name]['value']   = $annotation->value;
                            $metadata['enum'][$property->name]['literal'] = ( ! empty($annotation->literal))
                                ? $annotation->literal
                                : $annotation->value;
                        }
                    }
                }

                // choose the first property as default property
                $metadata['default_property'] = reset($metadata['properties']);
            }
        }

        self::$annotationMetadata[$name] = $metadata;
    }

    /**
     * Collects parsing metadata for a given attribute.
     *
     * @param array     $metadata
     * @param Attribute $attribute
     *
     * @return void
     */
    private function collectAttributeTypeMetadata(&$metadata, Attribute $attribute)
    {
        // handle internal type declaration
        $type = isset(self::$typeMap[$attribute->type])
            ? self::$typeMap[$attribute->type]
            : $attribute->type;

        // handle the case if the property type is mixed
        if ('mixed' === $type) {
            return;
        }

        // Evaluate type
        switch (true) {
            // Checks if the property has array<type>
            case (false !== $pos = strpos($type, '<')):
                $arrayType  = substr($type, $pos + 1, -1);
                $type       = 'array';

                if (isset(self::$typeMap[$arrayType])) {
                    $arrayType = self::$typeMap[$arrayType];
                }

                $metadata['attribute_types'][$attribute->name]['array_type'] = $arrayType;
                break;

            // Checks if the property has type[]
            case (false !== $pos = strrpos($type, '[')):
                $arrayType  = substr($type, 0, $pos);
                $type       = 'array';

                if (isset(self::$typeMap[$arrayType])) {
                    $arrayType = self::$typeMap[$arrayType];
                }

                $metadata['attribute_types'][$attribute->name]['array_type'] = $arrayType;
                break;
        }

        $metadata['attribute_types'][$attribute->name]['type']     = $type;
        $metadata['attribute_types'][$attribute->name]['value']    = $attribute->type;
        $metadata['attribute_types'][$attribute->name]['required'] = $attribute->required;
    }

	/**
	 * Annotation     ::= "@" AnnotationName MethodCall
	 * AnnotationName ::= QualifiedName | SimpleName
	 * QualifiedName  ::= NameSpacePart "\" {NameSpacePart "\"}* SimpleName
	 * NameSpacePart  ::= identifier | null | false | true
	 * SimpleName     ::= identifier | null | false | true
	 *
	 * @return mixed False if it is not a valid annotation.
	 *
	 * @throws AnnotationException
	 * @throws \ReflectionException
	 */
    private function Annotation()
    {
        $this->match(Lexer::T_AT);

        // check if we have an annotation
        $name = $this->Identifier();

        // only process names which are not fully qualified, yet
        // fully qualified names must start with a \
        $originalName = $name;

        if ('\\' !== $name[0]) {
            $pos = strpos($name, '\\');
            $alias = (false === $pos)? $name : substr($name, 0, $pos);
            $found = false;
            $loweredAlias = strtolower($alias);

            if ($this->namespaces) {
                foreach ($this->namespaces as $namespace) {
                    if ($this->classExists($namespace.'\\'.$name)) {
                        $name = $namespace.'\\'.$name;
                        $found = true;
                        break;
                    }
                }
            } elseif (isset($this->imports[$loweredAlias])) {
                $found = true;
                $name  = (false !== $pos)
                    ? $this->imports[$loweredAlias] . substr($name, $pos)
                    : $this->imports[$loweredAlias];
            } elseif ( ! isset($this->ignoredAnnotationNames[$name])
                && isset($this->imports['__NAMESPACE__'])
                && $this->classExists($this->imports['__NAMESPACE__'] . '\\' . $name)
            ) {
                $name  = $this->imports['__NAMESPACE__'].'\\'.$name;
                $found = true;
            } elseif (! isset($this->ignoredAnnotationNames[$name]) && $this->classExists($name)) {
                $found = true;
            }

            if ( ! $found) {
                if ($this->isIgnoredAnnotation($name)) {
                    return false;
                }

                throw AnnotationException::semanticalError(sprintf('The annotation "@%s" in %s was never imported. Did you maybe forget to add a "use" statement for this annotation?', $name, $this->context));
            }
        }

        $name = ltrim($name,'\\');

        if ( ! $this->classExists($name)) {
            throw AnnotationException::semanticalError(sprintf('The annotation "@%s" in %s does not exist, or could not be auto-loaded.', $name, $this->context));
        }

        // at this point, $name contains the fully qualified class name of the
        // annotation, and it is also guaranteed that this class exists, and
        // that it is loaded


        // collects the metadata annotation only if there is not yet
        if ( ! isset(self::$annotationMetadata[$name])) {
            $this->collectAnnotationMetadata($name);
        }

        // verify that the class is really meant to be an annotation and not just any ordinary class
        if (self::$annotationMetadata[$name]['is_annotation'] === false) {
            if ($this->ignoreNotImportedAnnotations || isset($this->ignoredAnnotationNames[$originalName])) {
                return false;
            }

            throw AnnotationException::semanticalError(sprintf('The class "%s" is not annotated with @Annotation. Are you sure this class can be used as annotation? If so, then you need to add @Annotation to the _class_ doc comment of "%s". If it is indeed no annotation, then you need to add @IgnoreAnnotation("%s") to the _class_ doc comment of %s.', $name, $name, $originalName, $this->context));
        }

        //if target is nested annotation
        $target = $this->isNestedAnnotation ? Target::TARGET_ANNOTATION : $this->target;

        // Next will be nested
        $this->isNestedAnnotation = true;

        //if annotation does not support current target
        if (0 === (self::$annotationMetadata[$name]['targets'] & $target) && $target) {
            throw AnnotationException::semanticalError(
                sprintf('Annotation @%s is not allowed to be declared on %s. You may only use this annotation on these code elements: %s.',
                     $originalName, $this->context, self::$annotationMetadata[$name]['targets_literal'])
            );
        }

        $values = $this->MethodCall();

        if (isset(self::$annotationMetadata[$name]['enum'])) {
            // checks all declared attributes
            foreach (self::$annotationMetadata[$name]['enum'] as $property => $enum) {
                // checks if the attribute is a valid enumerator
                if (isset($values[$property]) && ! in_array($values[$property], $enum['value'])) {
                    throw AnnotationException::enumeratorError($property, $name, $this->context, $enum['literal'], $values[$property]);
                }
            }
        }

        // checks all declared attributes
        foreach (self::$annotationMetadata[$name]['attribute_types'] as $property => $type) {
            if ($property === self::$annotationMetadata[$name]['default_property']
                && !isset($values[$property]) && isset($values['value'])) {
                $property = 'value';
            }

            // handle a not given attribute or null value
            if (!isset($values[$property])) {
                if ($type['required']) {
                    throw AnnotationException::requiredError($property, $originalName, $this->context, 'a(n) '.$type['value']);
                }

                continue;
            }

            if ($type['type'] === 'array') {
                // handle the case of a single value
                if ( ! is_array($values[$property])) {
                    $values[$property] = array($values[$property]);
                }

                // checks if the attribute has array type declaration, such as "array<string>"
                if (isset($type['array_type'])) {
                    foreach ($values[$property] as $item) {
                        if (gettype($item) !== $type['array_type'] && !$item instanceof $type['array_type']) {
                            throw AnnotationException::attributeTypeError($property, $originalName, $this->context, 'either a(n) '.$type['array_type'].', or an array of '.$type['array_type'].'s', $item);
                        }
                    }
                }
            } elseif (gettype($values[$property]) !== $type['type'] && !$values[$property] instanceof $type['type']) {
                throw AnnotationException::attributeTypeError($property, $originalName, $this->context, 'a(n) '.$type['value'], $values[$property]);
            }
        }

        // check if the annotation expects values via the constructor,
        // or directly injected into public properties
        if (self::$annotationMetadata[$name]['has_constructor'] === true) {
            return new $name($values);
        }

        $instance = new $name();

        foreach ($values as $property => $value) {
            if (!isset(self::$annotationMetadata[$name]['properties'][$property])) {
                if ('value' !== $property) {
                    throw AnnotationException::creationError(sprintf('The annotation @%s declared on %s does not have a property named "%s". Available properties: %s', $originalName, $this->context, $property, implode(', ', self::$annotationMetadata[$name]['properties'])));
                }

                // handle the case if the property has no annotations
                if ( ! $property = self::$annotationMetadata[$name]['default_property']) {
                    throw AnnotationException::creationError(sprintf('The annotation @%s declared on %s does not accept any values, but got %s.', $originalName, $this->context, json_encode($values)));
                }
            }

            $instance->{$property} = $value;
        }

        return $instance;
    }

	/**
	 * MethodCall ::= ["(" [Values] ")"]
	 *
	 * @return array
	 * @throws AnnotationException
	 */
    private function MethodCall()
    {
        $values = array();

        if ( ! $this->lexer->isNextToken(Lexer::T_OPEN_PARENTHESIS)) {
            return $values;
        }

        $this->match(Lexer::T_OPEN_PARENTHESIS);

        if ( ! $this->lexer->isNextToken(Lexer::T_CLOSE_PARENTHESIS)) {
            $values = $this->Values();
        }

        $this->match(Lexer::T_CLOSE_PARENTHESIS);

        return $values;
    }

	/**
	 * Values ::= Array | Value {"," Value}* [","]
	 *
	 * @return array
	 * @throws AnnotationException
	 */
    private function Values()
    {
        $values = array($this->Value());

        while ($this->lexer->isNextToken(Lexer::T_COMMA)) {
            $this->match(Lexer::T_COMMA);

            if ($this->lexer->isNextToken(Lexer::T_CLOSE_PARENTHESIS)) {
                break;
            }

            $token = $this->lexer->lookahead;
            $value = $this->Value();

            if ( ! is_object($value) && ! is_array($value)) {
                $this->syntaxError('Value', $token);
            }

            $values[] = $value;
        }

        foreach ($values as $k => $value) {
            if (is_object($value) && $value instanceof \stdClass) {
                $values[$value->name] = $value->value;
            } else if ( ! isset($values['value'])){
                $values['value'] = $value;
            } else {
                if ( ! is_array($values['value'])) {
                    $values['value'] = array($values['value']);
                }

                $values['value'][] = $value;
            }

            unset($values[$k]);
        }

        return $values;
    }

    /**
     * Constant ::= integer | string | float | boolean
     *
     * @return mixed
     *
     * @throws AnnotationException
     */
    private function Constant()
    {
        $identifier = $this->Identifier();

        if ( ! defined($identifier) && false !== strpos($identifier, '::') && '\\' !== $identifier[0]) {
            list($className, $const) = explode('::', $identifier);

            $pos = strpos($className, '\\');
            $alias = (false === $pos) ? $className : substr($className, 0, $pos);
            $found = false;
            $loweredAlias = strtolower($alias);

            switch (true) {
                case !empty ($this->namespaces):
                    foreach ($this->namespaces as $ns) {
                        if (class_exists($ns.'\\'.$className) || interface_exists($ns.'\\'.$className)) {
                             $className = $ns.'\\'.$className;
                             $found = true;
                             break;
                        }
                    }
                    break;

                case isset($this->imports[$loweredAlias]):
                    $found     = true;
                    $className = (false !== $pos)
                        ? $this->imports[$loweredAlias] . substr($className, $pos)
                        : $this->imports[$loweredAlias];
                    break;

                default:
                    if(isset($this->imports['__NAMESPACE__'])) {
                        $ns = $this->imports['__NAMESPACE__'];

                        if (class_exists($ns.'\\'.$className) || interface_exists($ns.'\\'.$className)) {
                            $className = $ns.'\\'.$className;
                            $found = true;
                        }
                    }
                    break;
            }

            if ($found) {
                 $identifier = $className . '::' . $const;
            }
        }

        // checks if identifier ends with ::class, \strlen('::class') === 7
        $classPos = stripos($identifier, '::class');
        if ($classPos === strlen($identifier) - 7) {
            return substr($identifier, 0, $classPos);
        }

        if (!defined($identifier)) {
            throw AnnotationException::semanticalErrorConstants($identifier, $this->context);
        }

        return constant($identifier);
    }

    /**
     * Value ::= PlainValue | FieldAssignment
     *
     * @return mixed
     */
    private function Value()
    {
        $peek = $this->lexer->glimpse();

        if (Lexer::T_EQUALS === $peek['type']) {
            return $this->FieldAssignment();
        }

        return $this->PlainValue();
    }

	/**
	 * PlainValue ::= integer | string | float | boolean | Array | Annotation
	 *
	 * @return mixed
	 * @throws AnnotationException
	 * @throws \ReflectionException
	 */
    private function PlainValue()
    {
        if ($this->lexer->isNextToken(Lexer::T_OPEN_CURLY_BRACES)) {
            return $this->Arrayx();
        }

        if ($this->lexer->isNextToken(Lexer::T_AT)) {
            return $this->Annotation();
        }

        if ($this->lexer->isNextToken(Lexer::T_IDENTIFIER)) {
            return $this->Constant();
        }

        switch ($this->lexer->lookahead['type']) {
            case Lexer::T_STRING:
                $this->match(Lexer::T_STRING);
                return $this->lexer->token['value'];

            case Lexer::T_INTEGER:
                $this->match(Lexer::T_INTEGER);
                return (int)$this->lexer->token['value'];

            case Lexer::T_FLOAT:
                $this->match(Lexer::T_FLOAT);
                return (float)$this->lexer->token['value'];

            case Lexer::T_TRUE:
                $this->match(Lexer::T_TRUE);
                return true;

            case Lexer::T_FALSE:
                $this->match(Lexer::T_FALSE);
                return false;

            case Lexer::T_NULL:
                $this->match(Lexer::T_NULL);
                return null;

            default:
                $this->syntaxError('PlainValue');
        }
    }

	/**
	 * FieldAssignment ::= FieldName "=" PlainValue
	 * FieldName ::= identifier
	 *
	 * @return \stdClass
	 * @throws AnnotationException
	 * @throws \ReflectionException
	 */
    private function FieldAssignment()
    {
        $this->match(Lexer::T_IDENTIFIER);
        $fieldName = $this->lexer->token['value'];

        $this->match(Lexer::T_EQUALS);

        $item = new \stdClass();
        $item->name  = $fieldName;
        $item->value = $this->PlainValue();

        return $item;
    }

	/**
	 * Array ::= "{" ArrayEntry {"," ArrayEntry}* [","] "}"
	 *
	 * @return array
	 * @throws AnnotationException
	 */
    private function Arrayx()
    {
        $array = $values = array();

        $this->match(Lexer::T_OPEN_CURLY_BRACES);

        // If the array is empty, stop parsing and return.
        if ($this->lexer->isNextToken(Lexer::T_CLOSE_CURLY_BRACES)) {
            $this->match(Lexer::T_CLOSE_CURLY_BRACES);

            return $array;
        }

        $values[] = $this->ArrayEntry();

        while ($this->lexer->isNextToken(Lexer::T_COMMA)) {
            $this->match(Lexer::T_COMMA);

            // optional trailing comma
            if ($this->lexer->isNextToken(Lexer::T_CLOSE_CURLY_BRACES)) {
                break;
            }

            $values[] = $this->ArrayEntry();
        }

        $this->match(Lexer::T_CLOSE_CURLY_BRACES);

        foreach ($values as $value) {
            list ($key, $val) = $value;

            if ($key !== null) {
                $array[$key] = $val;
            } else {
                $array[] = $val;
            }
        }

        return $array;
    }

	/**
	 * ArrayEntry ::= Value | KeyValuePair
	 * KeyValuePair ::= Key ("=" | ":") PlainValue | Constant
	 * Key ::= string | integer | Constant
	 *
	 * @return array
	 * @throws AnnotationException
	 * @throws \ReflectionException
	 */
    private function ArrayEntry()
    {
        $peek = $this->lexer->glimpse();

        if (Lexer::T_EQUALS === $peek['type']
                || Lexer::T_COLON === $peek['type']) {

            if ($this->lexer->isNextToken(Lexer::T_IDENTIFIER)) {
                $key = $this->Constant();
            } else {
                $this->matchAny(array(Lexer::T_INTEGER, Lexer::T_STRING));
                $key = $this->lexer->token['value'];
            }

            $this->matchAny(array(Lexer::T_EQUALS, Lexer::T_COLON));

            return array($key, $this->PlainValue());
        }

        return array(null, $this->Value());
    }

    /**
     * Checks whether the given $name matches any ignored annotation name or namespace
     *
     * @param string $name
     *
     * @return bool
     */
    private function isIgnoredAnnotation($name)
    {
        if ($this->ignoreNotImportedAnnotations || isset($this->ignoredAnnotationNames[$name])) {
            return true;
        }

        foreach (array_keys($this->ignoredAnnotationNamespaces) as $ignoredAnnotationNamespace) {
            $ignoredAnnotationNamespace = rtrim($ignoredAnnotationNamespace, '\\') . '\\';

            if (0 === stripos(rtrim($name, '\\') . '\\', $ignoredAnnotationNamespace)) {
                return true;
            }
        }

        return false;
    }
}
