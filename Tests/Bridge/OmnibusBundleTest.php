<?php

namespace Omnibus\Tests\Bridge;

use Omnibus\Bridge\Symfony\OmnibusBundle;
use Omnibus\GatewayInterface;
use Omnibus\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;

final class OmnibusBundleTest extends TestCase
{
    public function testTheGatewaysConfiguredAreBuiltAndInjectableByName(): void
    {
        $container = new ContainerBuilder();
        $container->register('http_client', MockHttpClient::class);
        $container->register(Shop::class)->setAutowired(true)->setPublic(true);
        $bundle = new OmnibusBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('omnibus', ['gateways' => [
            'retrait' => ['factory' => 'offline'],
            'relais' => ['factory' => 'mondial_relay', 'options' => ['sandbox' => true]],
        ]]);
        $container->compile();

        $registry = $container->get(Registry::class);
        self::assertSame(['retrait', 'relais'], array_keys($registry->all()));
        self::assertSame('mondial_relay', $registry->get('relais')->getName());

        $shop = $container->get(Shop::class);
        self::assertSame('offline', $shop->retrait->getName(), 'injected by its name');
        self::assertSame('mondial_relay', $shop->relais->getName());
    }
}

final class Shop
{
    public function __construct(public readonly GatewayInterface $retrait, public readonly GatewayInterface $relais)
    {
    }
}
