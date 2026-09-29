<?php

namespace Omnibus\Request;

/**
 * A question put to a gateway. The action that supports it answers by
 * setting the result; the gateway hands the request back.
 */
abstract class Request
{
    private mixed $result = null;
    private bool $answered = false;

    public function setResult(mixed $result): void
    {
        $this->result = $result;
        $this->answered = true;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function isAnswered(): bool
    {
        return $this->answered;
    }
}
