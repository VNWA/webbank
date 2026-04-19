<?php

namespace Tests\Feature;

use App\Enums\ApplicationRole;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagedDeviceManagementTest extends TestCase
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

    /**
     * @param  callable(Request): (Response|null)|null  $fallback
     */
    protected function fakeDuoPlusHttp(?int $statusCodeForStatusEndpoint = 2, ?callable $fallback = null): void
    {
        Http::fake(function (Request $request) use ($statusCodeForStatusEndpoint, $fallback) {
            $url = $request->url();

            if (str_contains($url, 'cloudPhone/status')) {
                $body = $request->data();
                $imageId = is_array($body) && isset($body['image_ids'][0])
                    ? (string) $body['image_ids'][0]
                    : 'unknown';

                return Http::response([
                    'code' => 200,
                    'message' => 'Success',
                    'data' => [
                        'list' => [
                            [
                                'id' => $imageId,
                                'name' => 'test-phone',
                                'status' => $statusCodeForStatusEndpoint,
                            ],
                        ],
                    ],
                ]);
            }

            if ($fallback !== null) {
                $custom = $fallback($request);
                if ($custom !== null) {
                    return $custom;
                }
            }

            return Http::response(['code' => 500, 'message' => 'Unfaked URL: '.$url], 503);
        });
    }

    public function test_plain_user_cannot_access_device_management_routes(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(ApplicationRole::User->value);

        $this->actingAs($actor)->get(route('device-management.index'))->assertForbidden();
        $this->actingAs($actor)->getJson(route('api.managed-devices.index'))->assertForbidden();
        $this->actingAs($actor)
            ->postJson(route('api.managed-devices.status-batch'), ['ids' => [1]])
            ->assertForbidden();
        $device = Device::factory()->create();
        $this->actingAs($actor)
            ->patchJson(route('api.managed-devices.note', $device), ['note' => 'x'])
            ->assertForbidden();
    }

    public function test_admin_status_batch_returns_live_statuses_per_device(): void
    {
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'cloudPhone/status')) {
                return Http::response(['code' => 500, 'message' => 'unfaked'], 503);
            }

            $body = $request->data();
            $ids = is_array($body['image_ids'] ?? null) ? $body['image_ids'] : [];
            $list = [];
            foreach ($ids as $id) {
                $idStr = (string) $id;
                $list[] = [
                    'id' => $idStr,
                    'status' => $idStr === 'img-a' ? 1 : 2,
                ];
            }

            return Http::response([
                'code' => 200,
                'message' => 'Success',
                'data' => [
                    'list' => $list,
                ],
            ]);
        });

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);

        $deviceA = Device::factory()->create([
            'user_id' => $admin->id,
            'duo_api_key' => 'duo-key',
            'image_id' => 'img-a',
        ]);
        $deviceB = Device::factory()->create([
            'user_id' => $admin->id,
            'duo_api_key' => 'duo-key',
            'image_id' => 'img-b',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.status-batch'), [
                'ids' => [$deviceA->id, $deviceB->id],
            ])
            ->assertOk()
            ->assertJsonPath('statuses.'.(string) $deviceA->id, 'on')
            ->assertJsonPath('statuses.'.(string) $deviceB->id, 'off');

        Http::assertSentCount(1);
    }

    public function test_admin_can_patch_device_note(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->patchJson(route('api.managed-devices.note', $device), ['note' => '  Ghi chú A  '])
            ->assertOk()
            ->assertJsonPath('data.note', 'Ghi chú A');

        $this->assertSame('Ghi chú A', $device->fresh()->note);
    }

    public function test_admin_can_clear_device_note(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'note' => 'Cũ',
        ]);

        $this->actingAs($admin)
            ->patchJson(route('api.managed-devices.note', $device), ['note' => ''])
            ->assertOk()
            ->assertJsonPath('data.note', null);

        $this->assertNull($device->fresh()->note);
    }

    public function test_admin_can_create_update_and_delete_device(): void
    {
        $this->fakeDuoPlusHttp(2);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);

        $payload = [
            'duo_api_key' => 'duo-key',
            'image_id' => 'image-1',
            'pg_pass' => 'pg-pass',
            'pg_pin' => '1234',
            'baca_pass' => 'bc-pass',
            'baca_pin' => '5678',
            'pg_video_id' => 'pg-video',
            'baca_video_id' => 'bc-video',
        ];

        $create = $this->actingAs($admin)
            ->postJson(route('api.managed-devices.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.device_status', 'off');

        $deviceId = (int) $create->json('data.id');

        $this->actingAs($admin)->putJson(
            route('api.managed-devices.update', $deviceId),
            [
                'pg_pass' => 'pg-pass-updated',
                'pg_pin' => '9999',
                'baca_pass' => 'bc-pass-updated',
                'baca_pin' => '8888',
                'pg_video_id' => 'pg-video-updated',
                'baca_video_id' => 'bc-video-updated',
            ],
        )->assertOk()
            ->assertJsonPath('data.device_status', 'off');

        $this->assertDatabaseHas('devices', ['id' => $deviceId, 'pg_pass' => 'pg-pass-updated']);
        $this->assertDatabaseHas('devices', ['id' => $deviceId, 'user_id' => $admin->id]);

        $this->actingAs($admin)->deleteJson(route('api.managed-devices.destroy', $deviceId))
            ->assertNoContent();
        $this->assertDatabaseMissing('devices', ['id' => $deviceId]);
    }

    public function test_admin_cannot_create_device_with_duplicate_image_id(): void
    {
        $this->fakeDuoPlusHttp(2);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);

        Device::factory()->create([
            'user_id' => $admin->id,
            'image_id' => 'dup-img',
            'duo_api_key' => 'key-a',
        ]);

        $payload = [
            'duo_api_key' => 'key-b',
            'image_id' => 'dup-img',
            'pg_pass' => 'pg-pass',
            'pg_pin' => '1234',
            'baca_pass' => 'bc-pass',
            'baca_pin' => '5678',
            'pg_video_id' => 'pg-video',
            'baca_video_id' => 'bc-video',
        ];

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image_id']);

        $this->assertSame(1, Device::query()->where('image_id', 'dup-img')->count());
    }

    public function test_admin_cannot_create_device_with_image_id_differing_only_by_leading_trailing_spaces(): void
    {
        $this->fakeDuoPlusHttp(2);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);

        Device::factory()->create([
            'user_id' => $admin->id,
            'image_id' => 'trim-img',
            'duo_api_key' => 'key-a',
        ]);

        $payload = [
            'duo_api_key' => 'key-b',
            'image_id' => '  trim-img  ',
            'pg_pass' => 'pg-pass',
            'pg_pin' => '1234',
            'baca_pass' => 'bc-pass',
            'baca_pin' => '5678',
            'pg_video_id' => 'pg-video',
            'baca_video_id' => 'bc-video',
        ];

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.store'), $payload)
            ->assertUnprocessable();
    }

    public function test_admin_can_bulk_delete_selected_devices(): void
    {
        $this->fakeDuoPlusHttp(2);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $devices = Device::factory()->count(3)->create([
            'status' => 'normal',
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('api.managed-devices.bulk-destroy'), [
                'ids' => [$devices[0]->id, $devices[1]->id],
            ])
            ->assertNoContent();

        $this->assertDatabaseMissing('devices', ['id' => $devices[0]->id]);
        $this->assertDatabaseMissing('devices', ['id' => $devices[1]->id]);
        $this->assertDatabaseHas('devices', ['id' => $devices[2]->id]);
    }

    public function test_admin_can_fetch_duoplus_info_to_prefill_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);

        Http::fake([
            'https://openapi.duoplus.net/api/v1/cloudPhone/info' => Http::response([
                'code' => 200,
                'data' => [
                    'name' => 'Cloud Device 9',
                    'status' => 'pending',
                    'device_status' => 'on',
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.duoplus-info'), [
                'duo_api_key' => 'dwin-123',
                'image_id' => 'img-123',
            ])
            ->assertOk()
            ->assertJsonPath('resolved.name', 'Cloud Device 9')
            ->assertJsonPath('resolved.status', 'pending')
            ->assertJsonPath('resolved.device_status', 'on');
    }

    public function test_admin_can_power_on_device_sync_when_duoplus_lists_image_in_success(): void
    {
        $statusCalls = 0;
        Http::fake(function (Request $request) use (&$statusCalls) {
            $url = $request->url();

            if (str_contains($url, 'cloudPhone/status')) {
                $statusCalls++;
                $body = $request->data();
                $imageId = is_array($body) && isset($body['image_ids'][0])
                    ? (string) $body['image_ids'][0]
                    : 'unknown';
                $code = $statusCalls >= 2 ? 1 : 2;

                return Http::response([
                    'code' => 200,
                    'message' => 'Success',
                    'data' => [
                        'list' => [
                            ['id' => $imageId, 'name' => 'x', 'status' => $code],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'cloudPhone/powerOn')) {
                return Http::response([
                    'code' => 200,
                    'message' => 'Success',
                    'data' => [
                        'success' => ['img-target'],
                        'fail' => [],
                    ],
                ]);
            }

            return Http::response(['code' => 500, 'message' => 'Unfaked: '.$url], 503);
        });

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'image_id' => 'img-target',
            'status' => 'normal',
            'duo_api_key' => 'key-1',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.power', $device), ['action' => 'on'])
            ->assertOk()
            ->assertJsonPath('data.device_status', 'on');
    }

    public function test_admin_power_on_returns_422_when_duoplus_lists_image_in_fail(): void
    {
        $this->fakeDuoPlusHttp(2, function (Request $request) {
            if (str_contains($request->url(), 'cloudPhone/powerOn')) {
                return Http::response([
                    'code' => 200,
                    'message' => 'Success',
                    'data' => [
                        'success' => [],
                        'fail' => ['img-target'],
                    ],
                ]);
            }

            return null;
        });

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'image_id' => 'img-target',
            'status' => 'normal',
            'duo_api_key' => 'key-1',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.power', $device), ['action' => 'on'])
            ->assertStatus(422);

        $this->assertSame('off', $device->fresh()->device_status);
    }

    public function test_admin_power_on_is_idempotent_when_device_already_on(): void
    {
        $this->fakeDuoPlusHttp(1);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'status' => 'normal',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.power', $device), ['action' => 'on'])
            ->assertOk()
            ->assertJsonPath('message', 'Máy đang bật.');

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'cloudPhone/powerOn'));
    }

    public function test_admin_can_update_device_when_row_status_is_pending(): void
    {
        $this->fakeDuoPlusHttp(2);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->putJson(route('api.managed-devices.update', $device), [
                'pg_pass' => 'x',
                'pg_pin' => '1111',
                'baca_pass' => 'y',
                'baca_pin' => '2222',
                'pg_video_id' => 'a',
                'baca_video_id' => 'b',
            ])
            ->assertOk();
    }

    public function test_power_endpoint_validates_action(): void
    {
        $this->fakeDuoPlusHttp(2);

        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create([
            'user_id' => $admin->id,
            'status' => 'normal',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.power', $device), ['action' => 'invalid'])
            ->assertUnprocessable();
    }
}
