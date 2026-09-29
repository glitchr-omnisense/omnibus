<?php

namespace Omnibus\Core;

use Omnibus\Core\Action\ActionInterface;
use Omnibus\Core\Action\ApiAwareInterface;
use Omnibus\Core\Action\ConfiguredRatingAction;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Payum way: a carrier's factory fills a Config - its name, title,
 * the options it needs, its API client ("omnibus.api", a closure of the
 * Config) and its actions ("omnibus.action.<name>") - and the gateway is
 * those actions, the API handed to the ones that ask for it.
 *
 * A "rates" option always gives a rating action from configuration
 * (Action\ConfiguredRatingAction), unless the carrier rates by API.
 */
abstract class GatewayFactory implements GatewayFactoryInterface
{
    public function __construct(protected readonly ?HttpClientInterface $http = null)
    {
    }

    public function getName(): string
    {
        return $this->createConfig()['omnibus.factory_name'];
    }

    public function create(array $options = []): GatewayInterface
    {
        $config = $this->createConfig($options);
        $config->validateNotEmpty($config->get('omnibus.required_options', []));

        $api = $config->get('omnibus.api');
        if ($api instanceof \Closure) {
            $api = $api($config);
        }

        $actions = [];
        foreach ($config as $key => $action) {
            if (!str_starts_with((string) $key, 'omnibus.action.')) {
                continue;
            }
            if ($action instanceof \Closure) {
                $action = $action($config);
            }
            if (!$action instanceof ActionInterface) {
                continue;
            }
            if ($action instanceof ApiAwareInterface && null !== $api) {
                $action->setApi($api);
            }
            $actions[] = $action;
        }
        // Configured prices last: a carrier's rating API answers first.
        if ($config->get('rates')) {
            $actions[] = new ConfiguredRatingAction($config['omnibus.factory_name'], $config['rates']);
        }

        return new Gateway($config['omnibus.factory_name'], $config['omnibus.factory_title'], $actions);
    }

    public function createConfig(array $options = []): Config
    {
        $config = new Config($options);
        $this->populateConfig($config);

        return $config;
    }

    abstract protected function populateConfig(Config $config): void;
}
