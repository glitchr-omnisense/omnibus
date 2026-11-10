<?php

namespace Omnibus;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ConfiguredRatingAction;
use Omnibus\Exception\UsageLimitReachedException;
use Omnibus\Http\NoRetry;
use Omnibus\Http\Redaction;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Provider\Config as BaseConfig;
use Omnisense\Contracts\Provider\ProviderFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Payum way: a carrier's factory fills a Config - its name, title,
 * the options it needs, its API client ("omnibus.api", a closure of the
 * Config), its actions ("omnibus.action.<name>") and its usage policy
 * ("omnibus.policy": the path of its policy.json, or a UsagePolicy) - and
 * the gateway is those actions, the API handed to the ones that ask for it.
 *
 * A "rates" option always gives a rating action from configuration
 * (Action\ConfiguredRatingAction), unless the carrier rates by API.
 *
 * It is omnisense/contracts' ProviderFactory: before the API is built, the
 * Config holds the carrier's Backoff (Config::backoff(), over the
 * registry's limiter), which the API observes on every answer
 * (Http\Throttle); guard() gives the gateway's PolicyGuard, its declared
 * policy tightened by the "policy" option and checked against "usage"
 * (Registry::guarded() puts the gateway behind it). The HTTP client given
 * is kept with its retries off (Http\NoRetry): a label or a pickup is never
 * sent twice by a retrying client.
 */
abstract class GatewayFactory extends ProviderFactory implements GatewayFactoryInterface
{
    protected readonly ?HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = null !== $http ? NoRetry::of($http) : null;
    }

    public function create(array $options = []): GatewayInterface
    {
        try {
            $gateway = parent::create($options);
        } catch (\Throwable $e) {
            // The options hold the credentials: no trace of the failure keeps them in its arguments.
            throw Redaction::withoutArguments($e);
        }

        return $gateway instanceof GatewayInterface ? $gateway : throw new \LogicException(static::class.' built no gateway.');
    }

    public function createConfig(array $options = []): Config
    {
        $config = parent::createConfig($options);

        return $config instanceof Config ? $config : throw new \LogicException('An omnibus factory fills an Omnibus\Config.');
    }

    abstract protected function populateConfig(Config $config): void;

    protected function newConfig(array $options): Config
    {
        return new Config($options);
    }

    protected function configure(BaseConfig $c): void
    {
        \assert($c instanceof Config);
        $this->populateConfig($c);
    }

    protected function make(BaseConfig $c): GatewayInterface
    {
        \assert($c instanceof Config);
        $api = $c->get('omnibus.api');
        if ($api instanceof \Closure) {
            $api = $api($c);
        }

        $actions = [];
        foreach ($c as $key => $action) {
            if (!str_starts_with((string) $key, 'omnibus.action.')) {
                continue;
            }
            if ($action instanceof \Closure) {
                $action = $action($c);
            }
            if (!$action instanceof ActionInterface) {
                continue;
            }
            if ($action instanceof ApiAwareInterface && \is_object($api)) {
                $action->setApi($api);
            }
            $actions[] = $action;
        }
        $name = $c->string('omnibus.factory_name') ?? static::class;
        // Configured prices last: a carrier's rating API answers first.
        $rates = $c->get('rates');
        if ($rates) {
            $actions[] = new ConfiguredRatingAction($name, $rates);
        }

        return new Gateway($name, $c->string('omnibus.factory_title') ?? $name, $actions, self::policy($c->get('omnibus.policy'), $name));
    }

    protected function limitException(): string
    {
        return UsageLimitReachedException::class;
    }

    /** The carrier's declared policy: a UsagePolicy, the path of a policy.json, or unknown. */
    private static function policy(mixed $policy, string $name): UsagePolicy
    {
        return match (true) {
            $policy instanceof UsagePolicy => $policy,
            \is_string($policy) && '' !== $policy => UsagePolicy::fromFile($policy),
            default => UsagePolicy::unknown($name),
        };
    }
}
