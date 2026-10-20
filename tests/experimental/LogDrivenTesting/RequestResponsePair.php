<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

use Koriym\SemanticLogger\CloseEntry;
use Koriym\SemanticLogger\OpenCloseEntry;

final class RequestResponsePair
{
    public function __construct(
        public readonly OpenCloseEntry $request,
        public readonly CloseEntry $response,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request' => $this->request->toArray(),
            'response' => $this->response->toArray(),
        ];
    }
}
