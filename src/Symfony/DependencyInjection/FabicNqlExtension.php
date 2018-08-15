<?php

namespace Fabic\Nql\Symfony\DependencyInjection;

use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;

class FabicNqlExtension extends Extension
{
    /**
     * @inheritdoc
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.xml');

        // hint Symfony about which of their classes contain annotations so they
        // are compiled when generating the application cache to improve the
        // overall performance [...]
        // If some class extends from other classes, all its parents are
        // automatically included in the list of classes to compile.
        $this->addAnnotatedClassesToCompile(array(
            // you can define the fully qualified class names...
            //'Fabic\\Nql\\Symfony\\Controller\\DefaultController',
            // ... but glob patterns are also supported:
            //'**Bundle\\Controller\\',
            //'Fabic\\Nql\\Symfony\\Controller\\',
            'Fabic\\Nql\\Symfony\\Controller\\NqlController',
        ));

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition('acme.social.twitter_client');
        $definition->replaceArgument(0, $config['twitter']['client_id']);
        $definition->replaceArgument(1, $config['twitter']['client_secret']);
    }
}
