<?php

namespace App\Domain\Integrations;

/**
 * What one node produced. $handle picks which outgoing edges fire (null = all, e.g. "true"/"false" for
 * branches); $halt stops the path entirely (a failed filter).
 */
class ActionResult
{
    public array $input = [];

    public function __construct(
        public mixed $output = null,
        public ?string $handle = null,
        public bool $halt = false,
        public ?int $httpStatus = null,
        public ?array $request = null,
    ) {}
}
