<?php

namespace App\Http\Presenters\User;

use App\Models\User;
use Illuminate\Support\Collection;
use App\Http\Presenters\CollectionAsArrayPresenterInterface;

class UserAsArrayForProductPresenter implements CollectionAsArrayPresenterInterface
{
    public function present(User $user): array
    {
        (array) $arrayUser = [
            'id' => $user->getId(),
            'login' => $user->getLogin(),
            'profile_img' => $user->getProfileImage(),
            'online' => $user->getOnline(),
            'roles' => $user->roles(),
            'createdAt' => $user->getCreatedAt(),
        ];

        return $arrayUser;
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
