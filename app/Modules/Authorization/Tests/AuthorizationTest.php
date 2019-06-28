<?php

namespace App\Modules\Authorization\Tests;

use App\Modules\Authorization\Actions\AssignPermissionsToRoleAction;
use App\Modules\Authorization\Actions\AssignPermissionToUserAction;
use App\Modules\Authorization\Actions\GetRolesAction;
use App\Modules\Authorization\Contracts\Roles;
use App\Modules\Authorization\Permissions\AuthorizationPermission;
use App\Modules\Authorization\Traits\ActAuthorized;
use App\Modules\User\Events\UserRegisteredEvent;
use App\Modules\User\Models\User;
use App\Packages\Actions\Traits\ApiActionRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Larapie\Core\Base\Test;
use Larapie\Core\Traits\ResetDatabase;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use \App\Modules\Authorization\Models\Permission;
use \App\Modules\Authorization\Models\Role;

class AuthorizationTest extends Test
{
    use ResetDatabase, ActAuthorized, ApiActionRunner;

    protected $permissions = [];

    protected $roles = Roles::MEMBER;

    public function testUpdateAuthorization()
    {
        $roles = Role::all()->pluck('name')->toArray();
        $this->assertEquals($roles, array_keys(config('authorization.roles')));
    }

    public function testAdminAssignmentRole()
    {
        $user = factory(User::class)->create();
        $this->assertFalse($user->hasRole(Roles::ADMIN));
        $user->assignRole(Roles::ADMIN);
        $this->assertTrue($user->hasRole(Roles::ADMIN));
    }

    public function testAssignDefaultRole()
    {
        $user = factory(User::class)->create();
        event(new UserRegisteredEvent($user));
        $this->assertTrue($user->hasRole(config('authorization.default_role')));
    }

    public function testUpdatePermissions()
    {
        Config::set('authorization.roles', [
            "somenewrole" => [
                "somepermission",
                "somepermission2"
            ]
        ]);
        $this->artisan('authorization:update', ['--delete' => true]);

        $this->assertNotNull(\Spatie\Permission\Models\Role::findByName('somenewrole'));
        $this->assertNotNull(\Spatie\Permission\Models\Permission::findByName('somepermission'));

        Config::set('authorization.permissions', [
            "somepermission",
            "somepermission2"
        ]);

        Config::set('authorization.roles', [
            "somenewrole" => [
                "somepermission2"
            ]
        ]);

        $this->artisan('authorization:update', ['--delete' => true]);

        $this->assertFalse(Role::findByName('somenewrole')->hasPermissionTo('somepermission'));

        Config::set('authorization.permissions', []);

        Config::set('authorization.roles', []);
        $this->artisan('authorization:update');

        $this->assertNotNull($role = Role::findByName('somenewrole'));
        $this->assertEmpty($role->getAllPermissions());

        $this->artisan('authorization:update', ['--delete' => true]);

        $this->expectException(PermissionDoesNotExist::class);
        Permission::findByName('somepermission');
    }

    public function testNonArrayRolePermission()
    {
        Config::set('authorization.roles', [
            "somenewrole" => "somepermission",
        ]);
        $this->artisan('authorization:update', ['--delete' => true]);

        $this->assertTrue(Role::findByName('somenewrole')->hasPermissionTo('somepermission'));
    }

    public function testAssignPermissionToUser()
    {
        $this->user()->givePermissionTo(AuthorizationPermission::ASSIGN_PERMISSION_TO_USER);

        $permissionName = 'somerandompermission';
        Permission::create(['name' => $permissionName]);

        $action = new AssignPermissionToUserAction([
            'user_id' => $this->user()->id,
            'permissions' => $permissionName
        ]);
        $action->run();

        $this->assertTrue($this->user()->hasPermissionTo($permissionName));

        $this->user()->revokePermissionTo(AuthorizationPermission::ASSIGN_PERMISSION_TO_USER);
        $this->assertFalse($this->user()->hasPermissionTo(AuthorizationPermission::ASSIGN_PERMISSION_TO_USER));


        $this->expectException(AuthorizationException::class);
        $action->actingAs($this->user());
        $action->run();
    }

    public function testAssignPermissionToRole()
    {
        $this->user()->givePermissionTo(AuthorizationPermission::ASSIGN_PERMISSION_TO_ROLE);

        $permissionNames = ['somerandompermission', 'somerandompermission2',];
        $roleName = 'somenewrole';

        Role::create(['name' => $roleName]);
        Permission::create(['name' => $permissionNames[0]]);
        Permission::create(['name' => $permissionNames[1]]);

        $action = new AssignPermissionsToRoleAction([
            'role' => $roleName,
            'permissions' => $permissionNames
        ]);
        $action->run();

        $this->assertTrue(Role::findByName($roleName)->hasPermissionTo($permissionNames[0]));
        $this->assertTrue(Role::findByName($roleName)->hasPermissionTo($permissionNames[1]));

        $this->user()->revokePermissionTo(AuthorizationPermission::ASSIGN_PERMISSION_TO_ROLE);
        $this->assertFalse($this->user()->hasPermissionTo(AuthorizationPermission::ASSIGN_PERMISSION_TO_ROLE));


        $this->expectException(AuthorizationException::class);
        $action = new AssignPermissionsToRoleAction([
            'role' => $roleName,
            'permissions' => $permissionNames[0]
        ]);
        $action->actingAs($this->user());
        $action->run();
    }

    public function testIndexRoles()
    {
        $this->user(function (User $user) {
            $user->assignRole(Role::ADMIN);
        });
        $this->assertTrue($this->user()->hasPermissionTo(AuthorizationPermission::INDEX_ROLES));

        $roles = $this->runActionFromApi(new GetRolesAction());

        collect($roles)->each(function ($role) {
            $this->assertArrayHasKeys(['name', 'permissions'], $role);
        });

        $roleNames = collect($roles)->pluck('name');
        $this->assertContains(Role::ADMIN, $roleNames);
        $this->assertContains(Role::MEMBER, $roleNames);
        $this->assertContains(Role::GUEST, $roleNames);
    }


}
