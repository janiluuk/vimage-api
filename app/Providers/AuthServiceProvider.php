<?php

namespace App\Providers;

use App\Policies\VideoJobPolicy;
use App\Models\Videojob;
use App\Models\ModelFile;
use App\Policies\ModelFilePolicy;
use App\Models\Tag;
use App\Models\Item;
use App\Models\User;
use App\Models\Generator;
use App\Models\Category;
use App\Policies\TagPolicy;
use App\Policies\ItemPolicy;
use App\Policies\RolePolicy;
use App\Policies\GeneratorPolicy;
use App\Policies\UserPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\PermissionPolicy;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        ModelFile::class => ModelFilePolicy::class,
        VideoJob::class => VideoJobPolicy::class,
	Generator::class => GeneratorPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
        Category::class => CategoryPolicy::class,
        Tag::class => TagPolicy::class,
        Item::class => ItemPolicy::class,
    ];
    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
       $this->registerPolicies();

    }
}
