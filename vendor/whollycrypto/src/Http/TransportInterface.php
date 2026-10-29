<?php

declare(strict_types=1);

namespace WhollyCrypto\Payment\Sdk\Http;

use WhollyCrypto\Payment\Sdk\Options;

interface TransportInterface
{
    public function send(
        #[\SensitiveParameter]
        Request $request,
        Options $options
    ): Response;
}
