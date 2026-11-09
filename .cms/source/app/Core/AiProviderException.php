<?php

declare(strict_types=1);

namespace App\Core;

final class AiProviderException extends \RuntimeException
{
    public function __construct(string$message, public readonly ?array $usage = null)
    {
        parent::__construct($message);
    }
}
