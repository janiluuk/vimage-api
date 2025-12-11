<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Exceptions\User\UserNotFoundException;
use App\Repositories\User\UserRepositoryInterface;

final class GetUserDataStatsAction
{
    private UserRepositoryInterface $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function execute(GetUserDataStatsRequest $request): GetUserDataStatsResponse
    {
        $user = $this->userRepository->getById($request->getUserId());

        if (!$user) {
            throw new UserNotFoundException();
        }

        $stats = $user->getDataStats();

        return new GetUserDataStatsResponse($stats);
    }
}
