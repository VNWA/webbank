<?php

namespace Tests\Feature;

use App\Enums\ApplicationRole;
use App\Jobs\ProcessDeviceOperation;
use App\Models\Device;
use App\Models\DeviceOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeviceOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ApplicationRole::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web']);
        }
    }

    public function test_admin_can_queue_pg_check_login_operation(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    'list' => [['id' => 'img-1', 'status' => 1]],
                ],
            ]),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'image_id' => 'img-1',
            'duo_api_key' => 'key-1',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.operations.store', $device), [
                'operation_type' => 'pg_check_login',
            ])
            ->assertStatus(202)
            ->assertJsonPath('operation.status', 'queued');

        Queue::assertPushed(ProcessDeviceOperation::class);
        $this->assertDatabaseHas('device_operations', [
            'device_id' => $device->id,
            'operation_type' => 'pg_check_login',
            'status' => 'queued',
        ]);
    }

    public function test_cannot_queue_new_operation_when_device_has_running_operation(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response([
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    'list' => [['id' => 'img-2', 'status' => 1]],
                ],
            ]),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
        ]);

        DeviceOperation::query()->create([
            'device_id' => $device->id,
            'requested_by' => $admin->id,
            'operation_type' => 'pg_check_login',
            'status' => 'running',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.operations.store', $device), [
                'operation_type' => 'baca_check_login',
            ])
            ->assertStatus(409);
    }

    public function test_process_pg_check_login_job_updates_success_and_logs(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v1/cloudPhone/command')) {
                $payload = $request->data();
                $command = (string) ($payload['command'] ?? '');

                if (str_contains($command, 'uiautomator dump')) {
                    return Http::response([
                        'code' => 200,
                        'message' => 'Success',
                        'data' => [
                            'success' => true,
                            'content' => '<node text="Xin chào"/> <node text="Chuyển tiền"/>',
                        ],
                    ]);
                }

                return Http::response([
                    'code' => 200,
                    'message' => 'Success',
                    'data' => ['success' => true],
                ]);
            }

            if (str_contains($request->url(), '/api/v1/cloudPhone/status')) {
                return Http::response([
                    'code' => 200,
                    'message' => 'Success',
                    'data' => [
                        'list' => [['id' => 'img-3', 'status' => 1]],
                    ],
                ]);
            }

            return Http::response(['code' => 500, 'message' => 'Unexpected'], 500);
        });

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'image_id' => 'img-3',
            'duo_api_key' => 'key-3',
            'pg_pass' => 'pass123',
        ]);

        $operation = DeviceOperation::query()->create([
            'device_id' => $device->id,
            'requested_by' => $admin->id,
            'operation_type' => 'pg_check_login',
            'status' => 'queued',
        ]);

        (new ProcessDeviceOperation($operation->id))->handle(app(\App\Services\DuoPlusApi::class));

        $operation->refresh();
        $this->assertSame('success', $operation->status);
        $this->assertGreaterThanOrEqual(11, \App\Models\DeviceOperationLog::query()->count());
    }
}
