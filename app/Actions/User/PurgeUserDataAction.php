<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Exceptions\User\UserNotFoundException;
use App\Repositories\User\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;

final class PurgeUserDataAction
{
    private UserRepositoryInterface $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function execute(PurgeUserDataRequest $request): PurgeUserDataResponse
    {
        $user = $this->userRepository->getById($request->getUserId());

        if (!$user) {
            throw new UserNotFoundException();
        }

        // Collect counts before deletion
        $counts = [
            'products' => $user->products()->count(),
            'video_jobs' => $user->videoJobs()->count(),
            'items' => $user->items()->count(),
            'messages' => $user->messages()->count(),
            'chats' => $user->chat()->count(),
            'orders' => $user->orders()->count(),
            'finance_operations' => $user->financeOperations()->count(),
            'support_requests' => $user->supportRequests()->count(),
            'media' => $user->getMedia()->count(),
        ];

        // Delete all user data in a transaction
        DB::transaction(function () use ($user) {
            // Delete relationships (some may cascade automatically)
            $user->products()->delete();
            $user->videoJobs()->delete();
            $user->items()->delete();
            $user->messages()->delete();
            $user->chat()->delete();
            $user->orders()->delete();
            $user->financeOperations()->delete();
            $user->supportRequests()->delete();
            
            // Clear media files
            $user->clearMediaCollection();
        });

        return new PurgeUserDataResponse($counts);
    }
}
