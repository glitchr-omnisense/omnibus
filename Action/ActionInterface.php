<?php

namespace Omnibus\Core\Action;

use Omnibus\Core\Request\Request;

/** One thing a carrier does: answers the requests it supports. */
interface ActionInterface
{
    public function supports(Request $request): bool;

    /** Sets the request's result, or throws Omnibus\Core\Exception\CarrierException. */
    public function execute(Request $request): void;
}
