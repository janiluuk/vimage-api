<?php
declare(strict_types=1);

namespace App\Constant;

use BenSampo\Enum\Enum;

final class PromoCodeConstant extends Enum
{
    public const FIXED = 'fixed';

    public const PERCENTAGE = 'percentage';
}