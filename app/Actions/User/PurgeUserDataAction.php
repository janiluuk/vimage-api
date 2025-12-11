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
            // Force delete relationships (permanently remove, bypassing soft deletes)
            $user->products()->forceDelete();
            $user->videoJobs()->forceDelete();
            $user->items()->forceDelete();
            $user->messages()->forceDelete();
            
            // For chats, we need to delete each one individually since chat() returns a query builder
            $user->chat()->get()->each(function ($chat) {
                $chat->forceDelete();
            });
            
            $user->orders()->forceDelete();
            $user->financeOperations()->forceDelete();
            $user->supportRequests()->forceDelete();
            
            // Clear media files
            $user->clearMediaCollection();
        });

        return new PurgeUserDataResponse($counts);
    }
}
