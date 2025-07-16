<?php

declare(strict_types=1);

namespace SocketPool\Exceptions;

/**
 * Base Socket Pool Exception
 */
class SocketPoolException extends \Exception
{
    protected array $context = [];

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }
}
