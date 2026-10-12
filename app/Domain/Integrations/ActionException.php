<?php

namespace App\Domain\Integrations;

use RuntimeException;
use Throwable;

/**
 * A node failed. Carries whatever request/response data exists so the debug drawer can show it.
 */
class ActionException extends RuntimeException
{
    public array $input = [];

    public function __construct(
        string $message,
        public ?int $httpStatus = null,
        public mixed $output = null,
        public ?array $request = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
