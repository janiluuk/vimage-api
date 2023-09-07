<?php

declare(strict_types=1);

namespace App\Constant;
use BenSampo\Enum\Enum;

final class OrderPaymentConstant extends Enum
{
    public const CASH = 'cash';

    public const STRIPE = 'stripe';

    public const BANK_TRANSFER = 'bank_transfer';
}