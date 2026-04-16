<?php

namespace Tests\Feature;

use App\Enums\ApplicationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagedUserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ApplicationRole::cases() as $role) {
            Role::firstOrCreate(
                ['name' => $role->value, 'guard_name' => 'web'],
            );
        }
    }

    public function test_plain_user_cannot_access_managed_users_api(): void
    {
        $user = User::factory()->create();
        $user->assignRole(ApplicationRole::User->value);

        $this->actingAs($user)
            ->getJson(route('api.managed-users.index'))
            ->assertForbidden();
    }

    public function test_plain_user_cannot_open_user_management_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole(ApplicationRole::User->value);

        $this->actingAs($user)
            ->get(route('user-management.index'))
            ->assertForbidden();
    }

    public function test_admin_index_excludes_superadmin_users(): void
    {
        $super = User::factory()->create(['email' => 'super@example.com']);
        $super->assignRole(ApplicationRole::SuperAdmin->value);

        $adminUser = User::factory()->create(['email' => 'admin@example.com']);
        $adminUser->assignRole(ApplicationRole::Admin->value);

        $response = $this->actingAs($adminUser)
            ->getJson(route('api.managed-users.index', ['per_page' => 50]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($super->id, $ids);
    }

    public function test_superadmin_index_includes_superadmin_users(): void
    {
        $super = User::factory()->create(['email' => 'super2@example.com']);
        $super->assignRole(ApplicationRole::SuperAdmin->value);

        $actor = User::factory()->create(['email' => 'super3@example.com']);
        $actor->assignRole(ApplicationRole::SuperAdmin->value);

        $response = $this->actingAs($actor)
            ->getJson(route('api.managed-users.index', ['per_page' => 50]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($super->id, $ids);
    }

    public function test_admin_cannot_update_superadmin_user(): void
    {
        $super = User::factory()->create(['email' => 'target@example.com']);
        $super->assignRole(ApplicationRole::SuperAdmin->value);

        $adminUser = User::factory()->create(['email' => 'mgr@example.com']);
        $adminUser->assignRole(ApplicationRole::Admin->value);

        $this->actingAs($adminUser)
            ->putJson(route('api.managed-users.update', $super), [
                'name' => 'Hacked',
                'email' => $super->email,
                'role' => ApplicationRole::User->value,
            ])
            ->assertForbidden();
    }

    public function test_superadmin_can_update_user_role(): void
    {
        $target = User::factory()->create(['email' => 'target2@example.com']);
        $target->assignRole(ApplicationRole::User->value);

        $actor = User::factory()->create(['email' => 'root@example.com']);
        $actor->assignRole(ApplicationRole::SuperAdmin->value);

        $this->actingAs($actor)
            ->putJson(route('api.managed-users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => ApplicationRole::Admin->value,
            ])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole(ApplicationRole::Admin->value));
    }
}
