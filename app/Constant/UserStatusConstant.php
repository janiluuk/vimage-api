<?php

declare(strict_types=1);

namespace App\Constant;

use BenSampo\Enum\Enum;

final class UserStatusConstant extends Enum
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const BLOCKED = 'blocked';
}