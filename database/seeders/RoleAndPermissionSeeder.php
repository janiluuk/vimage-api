<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;
class RoleAndPermissionSeeder extends Seeder
{
    public function run()
    {
            // Reset cached roles and permissions
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        
    
            
    
            // Assign roles to demo users
            $superadmin = User::where('id',1)->first();
    
            $superadmin->assignRole('super-admin');
    
            $admin = User::where('id',1)->first();
            $admin->assignRole('admin');

    }
}
