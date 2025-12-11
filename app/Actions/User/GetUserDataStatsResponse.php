<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Response;

final class GetUserDataStatsResponse implements Response
{
    private array $stats;

    public function __construct(array $stats)
    {
        $this->stats = $stats;
    }

    public function getResponse(): array
    {
        return $this->stats;
    }
}
