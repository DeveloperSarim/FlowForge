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

    /** Methods that change nothing, or change the same thing however often they're sent. */
    private const IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /** cURL errors that mean the request never reached the app: no DNS answer, no connection, no TLS handshake. */
    private const NOT_SENT = [6, 7, 35];

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

    /**
     * The request may have reached the app and done its job although we saw an error: a POST/PATCH that timed
     * out, lost its connection mid-way or got a 500/502/504. Sending it again could create a second order or
     * message, so it isn't retried automatically, unless it carried an idempotency key the app de-duplicates on.
     * 408, 425, 429 and 503 mean the app didn't process it.
     */
    public function mayHaveGoneThrough(): bool
    {
        $method = strtoupper((string) ($this->request['method'] ?? ''));
        if ($method === '' || in_array($method, self::IDEMPOTENT, true) || ! empty($this->request['idempotency_key'])) {
            return false;
        }
        if ($this->httpStatus !== null) {
            return in_array($this->httpStatus, [500, 502, 504], true) || $this->httpStatus > 504;
        }
        for ($e = $this->getPrevious(); $e; $e = $e->getPrevious()) {
            if ($e instanceof ConnectionException) {
                return ! (preg_match('/cURL error (\d+)/', $e->getMessage(), $m) && in_array((int) $m[1], self::NOT_SENT, true));
            }
        }

        return false;
    }
}
