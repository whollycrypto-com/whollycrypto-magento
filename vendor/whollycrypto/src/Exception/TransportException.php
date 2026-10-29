<?php

declare(strict_types=1);

namespace WhollyCrypto\Payment\Sdk\Exception;

/** @property-read bool $retryable */
final class TransportException extends \RuntimeException implements \JsonSerializable
{
    use \WhollyCrypto\Payment\Sdk\Internal\RejectDynamicProperties;

    private const READABLE_PROPERTIES = ['retryable'];
    private bool $retryable;

    public function __construct(string $message, bool $retryable = false)
    {
        $this->initializeImmutable();
        $this->retryable = $retryable;
        parent::__construct($message);
    }
}
