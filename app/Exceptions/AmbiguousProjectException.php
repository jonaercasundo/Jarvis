<?php

namespace App\Exceptions;

class AmbiguousProjectException extends \RuntimeException
{
    /** @param list<array{project_id: int, project_name: string, ref_no?: string}> $candidates */
    public function __construct(public readonly array $candidates, public readonly ?string $reference = null)
    {
        parent::__construct('Project search is ambiguous. Which project do you mean?');
    }
}
