<?php

namespace Tests\Feature;

use App\Enums\ApplicationRole;
use App\Models\Bank;
use App\Models\Device;
use App\Models\SavedTransferRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SavedTransferRecipientTest extends TestCase
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

    public function test_guest_cannot_store_saved_recipient(): void
    {
        $device = Device::factory()->create();
        $bank = Bank::query()->create([
            'code' => 'TST',
            'name' => 'Test Bank',
            'short_name' => 'TST',
            'pg_name' => 'Test',
            'baca_name' => 'Test',
        ]);

        $this->postJson(route('api.managed-devices.saved-transfer-recipients.store', $device), [
            'bank_id' => $bank->id,
            'account_number' => '0123456789',
            'recipient_name' => 'A',
        ])->assertUnauthorized();
    }

    public function test_admin_can_store_saved_recipient_and_receive_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create(['user_id' => $admin->id]);
        $bank = Bank::query()->create([
            'code' => 'TST',
            'name' => 'Test Bank',
            'short_name' => 'TST',
            'pg_name' => 'Test PG',
            'baca_name' => 'Test Baca',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.saved-transfer-recipients.store', $device), [
                'bank_id' => $bank->id,
                'account_number' => '0123 456 789',
                'recipient_name' => 'Nguyen Van A',
            ])
            ->assertOk()
            ->assertJsonPath('recipients.0.bank_id', $bank->id)
            ->assertJsonPath('recipients.0.account_number', '0123456789')
            ->assertJsonPath('recipients.0.recipient_name', 'Nguyen Van A');

        $this->assertDatabaseHas('saved_transfer_recipients', [
            'device_id' => $device->id,
            'bank_id' => $bank->id,
            'account_number' => '0123456789',
            'recipient_name' => 'Nguyen Van A',
        ]);
    }

    public function test_store_updates_existing_row_for_same_device_bank_and_account(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create(['user_id' => $admin->id]);
        $bank = Bank::query()->create([
            'code' => 'TST',
            'name' => 'Test Bank',
            'short_name' => 'TST',
            'pg_name' => 'Test',
            'baca_name' => 'Test',
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.saved-transfer-recipients.store', $device), [
                'bank_id' => $bank->id,
                'account_number' => '0123456789',
                'recipient_name' => 'Old',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.saved-transfer-recipients.store', $device), [
                'bank_id' => $bank->id,
                'account_number' => '0123456789',
                'recipient_name' => 'New',
            ])
            ->assertOk()
            ->assertJsonPath('recipients.0.recipient_name', 'New');

        $this->assertSame(1, SavedTransferRecipient::query()->where('device_id', $device->id)->count());
    }

    public function test_store_rejects_invalid_bank_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->postJson(route('api.managed-devices.saved-transfer-recipients.store', $device), [
                'bank_id' => 999_999,
                'account_number' => '0123456789',
                'recipient_name' => 'A',
            ])
            ->assertUnprocessable();
    }

    public function test_store_prunes_to_thirty_recipients_per_device(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(ApplicationRole::Admin->value);
        $device = Device::factory()->create(['user_id' => $admin->id]);
        $bank = Bank::query()->create([
            'code' => 'TST',
            'name' => 'Test Bank',
            'short_name' => 'TST',
            'pg_name' => 'Test',
            'baca_name' => 'Test',
        ]);

        for ($i = 1; $i <= 31; $i++) {
            $this->actingAs($admin)
                ->postJson(route('api.managed-devices.saved-transfer-recipients.store', $device), [
                    'bank_id' => $bank->id,
                    'account_number' => str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                    'recipient_name' => 'R'.$i,
                ])
                ->assertOk();
        }

        $this->assertSame(30, SavedTransferRecipient::query()->where('device_id', $device->id)->count());
        $this->assertSame(30, count(SavedTransferRecipient::rowsForTransferPage($device)));
    }
}
