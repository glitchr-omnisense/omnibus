<?php

namespace Omnibus\Action;

use Omnibus\Exception\InvalidConfigException;

/**
 * @template T of object
 */
trait ApiAwareTrait
{
    /** @var T */
    protected object $api;

    /** @var class-string<T> set in the constructor */
    protected string $apiClass;

    public function setApi(object $api): void
    {
        if (!$api instanceof $this->apiClass) {
            throw new InvalidConfigException(\sprintf('%s needs a %s, got %s.', static::class, $this->apiClass, $api::class));
        }
        $this->api = $api;
    }
}
