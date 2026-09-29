<?php

namespace Omnibus;

/** Builds a carrier's gateway from its options (credentials, sandbox, rates...). */
interface GatewayFactoryInterface
{
    /** The name gateways are configured with: "mondial_relay", "colissimo"... */
    public function getName(): string;

    /** @param array<string, mixed> $options */
    public function create(array $options = []): GatewayInterface;
}
