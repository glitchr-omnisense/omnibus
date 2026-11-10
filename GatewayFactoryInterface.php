<?php

namespace Omnibus;

use Omnisense\Contracts\Provider\ProviderFactoryInterface;

/** Builds a carrier's gateway from its options (credentials, sandbox, rates...): an omnisense/contracts provider factory. */
interface GatewayFactoryInterface extends ProviderFactoryInterface
{
    /** The name gateways are configured with: "mondial_relay", "colissimo"... */
    public function getName(): string;

    /** @param array<string, mixed> $options */
    public function create(array $options = []): GatewayInterface;
}
