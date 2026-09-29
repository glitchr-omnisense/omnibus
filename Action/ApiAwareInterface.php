<?php

namespace Omnibus\Core\Action;

/** An action that talks to the carrier through the gateway's API client. */
interface ApiAwareInterface
{
    public function setApi(object $api): void;
}
