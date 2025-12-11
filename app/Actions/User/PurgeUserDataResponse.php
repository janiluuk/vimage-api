<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Response;

final class PurgeUserDataResponse implements Response
{
    private array $purgedCounts;

    public function __construct(array $purgedCounts)
    {
        $this->purgedCounts = $purgedCounts;
    }

    public function getResponse(): array
    {
        return $this->purgedCounts;
    }
}
