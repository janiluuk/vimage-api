<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ModelFile;
use Illuminate\Auth\Access\HandlesAuthorization;

class ModelFilePolicy
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
     * @param  \App\Models\ModelFile  $modelFile
     * @return mixed
     */
    public function view(User $user, ModelFile $modelFile)
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
     * @param  \App\Models\ModelFile  $modelFile
     * @return mixed
     */
    public function update(User $user, ModelFile $modelFile)
    {
        // Only admin users can update model files
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can delete the model file.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\ModelFile  $modelFile
     * @return mixed
     */
    public function delete(User $user, ModelFile $modelFile)
    {
        // Only admin users can delete model files
        return $user->role === 'admin';
    }
}
