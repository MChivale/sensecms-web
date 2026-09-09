<?php

declare(strict_types=1);

namespace SenseCMS\Calendar;

use RuntimeException;

final class CalendarConflictException extends RuntimeException
{
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('The selected time conflicts with an existing event or resource reservation.', 409);
    }
}
