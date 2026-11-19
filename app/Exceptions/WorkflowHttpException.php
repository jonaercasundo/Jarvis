<?php

namespace App\Exceptions;

class WorkflowHttpException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly array $details)
    {
        parent::__construct($details['message']);
    }
}
