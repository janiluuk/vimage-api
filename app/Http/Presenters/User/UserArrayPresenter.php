<?php

declare(strict_types=1);

namespace App\Http\Presenters\User;

use App\Models\User;
use Illuminate\Support\Collection;
use App\Http\Presenters\CollectionAsArrayPresenterInterface;

final class UserArrayPresenter implements CollectionAsArrayPresenterInterface
{
    public function present(User $user): array
    {
        $roles = [];
        $role = $user->roles()->first();
        if ($role) {
            $roles[] = $role->name;
        }

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'login' => $user->getLogin(),
            'profile_image' => $user->getProfileImage(),
            'online' => $user->getOnline(),
            'confirmSendEmail' => $user->getConfirmSendEmail(),
            'balance' => (int)$user->getBalance(),
            'google_id' => $user->getGoogleId(),
            'discord_id' => $user->getDiscordId(),
            'stripe_id' => $user->getStripeId(),
            'roles' => $roles,
            'passwordResetAdmin' => $user->getPasswordResetAdmin(),
            'createdAt' => $user->getCreatedAt(),
        ];
    }

    public function presentCollection(Collection $collection): array
    {
        return $collection
            ->map(
                function (User $user) {
                    return $this->present($user);
                }
            )
            ->all();
    }
}
