<?php

namespace App\Domain\Integrations;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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

    /** Worth trying again later: the app was unreachable, timed out, rate-limited us or had a server error. */
    public function isTemporary(): bool
    {
        if ($this->httpStatus !== null) {
            return in_array($this->httpStatus, [408, 425, 429], true) || $this->httpStatus >= 500;
        }
        for ($e = $this->getPrevious(); $e; $e = $e->getPrevious()) {
            if ($e instanceof ConnectionException || $e instanceof TransportExceptionInterface) {
                return true;
            }
        }

        return false;
    }
}
