<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Generator;
use Illuminate\Auth\Access\HandlesAuthorization;

class GeneratorPolicy
{

    /**
     * Determine whether the user can view any model files.
     * 
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function viewAny(User $user)
    {
        // All users can view model files
        return true;
    }

    /**
     * Determine whether the user can view the model file.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Generator  $Generator
     * @return mixed
     */
    public function view(User $user, Generator $Generator)
    {
        // All users can view specific model files
        return true;
    }

    /**
     * Determine whether the user can create model files.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function create(User $user)
    {
        // Only admin users can create model files
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can update the model file.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Generator  $Generator
     * @return mixed
     */
    public function update(User $user, Generator $Generator)
    {
        // Only admin users can update model files
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can delete the model file.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Generator  $Generator
     * @return mixed
     */
    public function delete(User $user, Generator $Generator)
    {
        // Only admin users can delete model files
        return $user->role === 'admin';
    }
}
