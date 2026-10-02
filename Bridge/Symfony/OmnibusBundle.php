<?php

namespace Omnibus\Bridge\Symfony;

use Omnibus\Amazon\AmazonGatewayFactory;
use Omnibus\Aramex\AramexGatewayFactory;
use Omnibus\Auspost\AuspostGatewayFactory;
use Omnibus\Bluedart\BluedartGatewayFactory;
use Omnibus\CanadaPost\CanadaPostGatewayFactory;
use Omnibus\Canpar\CanparGatewayFactory;
use Omnibus\Chronopost\ChronopostGatewayFactory;
use Omnibus\Colissimo\ColissimoGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\GatewayInterface;
use Omnibus\DbSchenker\DbSchenkerGatewayFactory;
use Omnibus\Dhl\DhlGatewayFactory;
use Omnibus\Dtdc\DtdcGatewayFactory;
use Omnibus\Fedex\FedexGatewayFactory;
use Omnibus\Gls\GlsGatewayFactory;
use Omnibus\JdlExpress\JdlExpressGatewayFactory;
use Omnibus\MondialRelay\MondialRelayGatewayFactory;
use Omnibus\Offline\OfflineGatewayFactory;
use Omnibus\Purolator\PurolatorGatewayFactory;
use Omnibus\RoyalMail\RoyalMailGatewayFactory;
use Omnibus\SfExpress\SfExpressGatewayFactory;
use Omnibus\Tnt\TntGatewayFactory;
use Omnibus\Ups\UpsGatewayFactory;
use Omnibus\Usps\UspsGatewayFactory;
use Omnibus\ZtoExpress\ZtoExpressGatewayFactory;
use Omnibus\Registry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Omnibus in a Symfony application: the carrier packages installed
 * (omnibus/offline, omnibus/mondial-relay, omnibus/colissimo, omnibus/ups...
 * every omnibus/* package) registered,
 * the shop's gateways built from configuration, Omnibus\Registry autowired,
 * and each gateway injectable by its name:
 *
 *     omnibus:
 *         gateways:
 *             relais: { factory: mondial_relay, options: { enseigne: '%env(MONDIAL_RELAY_ENSEIGNE)%', private_key: '%env(MONDIAL_RELAY_PRIVATE_KEY)%' } }
 *             retrait: { factory: offline }
 *
 *     public function __construct(GatewayInterface $relais) {}
 *
 * An application's own factories (a GatewayFactoryInterface) are registered
 * too, autoconfigured.
 */
final class OmnibusBundle extends AbstractBundle
{
    protected string $extensionAlias = 'omnibus';

    /** The carrier packages this bundle knows, registered when installed. */
    private const FACTORIES = [
        OfflineGatewayFactory::class, MondialRelayGatewayFactory::class, ColissimoGatewayFactory::class, ChronopostGatewayFactory::class,
        UpsGatewayFactory::class, FedexGatewayFactory::class, DhlGatewayFactory::class, TntGatewayFactory::class, GlsGatewayFactory::class, DbSchenkerGatewayFactory::class,
        UspsGatewayFactory::class, RoyalMailGatewayFactory::class, CanadaPostGatewayFactory::class, PurolatorGatewayFactory::class, CanparGatewayFactory::class, AuspostGatewayFactory::class,
        AramexGatewayFactory::class, BluedartGatewayFactory::class, DtdcGatewayFactory::class, SfExpressGatewayFactory::class, JdlExpressGatewayFactory::class, ZtoExpressGatewayFactory::class, AmazonGatewayFactory::class,
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('gateways')
                    ->info('The shop\'s carriers, by name: a factory (offline, mondial_relay...) and its options.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('factory')->isRequired()->cannotBeEmpty()->end()
                            ->variableNode('options')->defaultValue([])->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array{gateways: array<string, array{factory: string, options: array<string, mixed>}>} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(GatewayFactoryInterface::class)->addTag('omnibus.gateway_factory');

        $services = $container->services();
        foreach (self::FACTORIES as $factory) {
            if (class_exists($factory) && is_subclass_of($factory, GatewayFactoryInterface::class)) {
                $services->set($factory)->args([service('http_client')->nullOnInvalid()])->tag('omnibus.gateway_factory');
            }
        }

        $services->set(Registry::class)
            ->args([tagged_iterator('omnibus.gateway_factory'), $config['gateways']])
            ->public();

        foreach (array_keys($config['gateways']) as $name) {
            $id = 'omnibus.gateway.'.$name;
            $services->set($id, GatewayInterface::class)->factory([service(Registry::class), 'get'])->args([$name]);
            $builder->registerAliasForArgument($id, GatewayInterface::class, $name);
        }
    }
}
