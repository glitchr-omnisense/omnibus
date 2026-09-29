<?php

namespace Omnibus\Core\Exception;

use Omnibus\Core\Request\Request;

/** The carrier does not do that (no pickup points, no cancellation...). */
final class RequestNotSupportedException extends \LogicException implements OmnibusException
{
    public static function for(Request $request, string $gateway): self
    {
        return new self(\sprintf('The "%s" gateway does not support %s.', $gateway, (new \ReflectionClass($request))->getShortName()));
    }
}
