<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\DashboardController;
use PDO;

final class ExtensionContext
{
    public function __construct(
        public readonly PDO $db,
        public readonly Auth $auth,
        public readonly AccessControl $access,
        public readonly DashboardController $dashboard,
        public readonly array $config,
        public readonly string $root
    ) {}
}
