<?php

namespace Tests\Feature;

use App\Models\Bank;
use Database\Seeders\BankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_seeder_inserts_static_banks_with_pg_and_baca_names(): void
    {
        $this->seed(BankSeeder::class);

        $this->assertGreaterThanOrEqual(80, Bank::query()->count());

        $acb = Bank::query()->where('code', 'ACB')->first();
        $this->assertNotNull($acb);
        $this->assertSame('A CHAU (ACB)', $acb->pg_name);
        $this->assertSame('ACB', $acb->baca_name);

        $vcb = Bank::query()->where('code', 'VCB')->first();
        $this->assertNotNull($vcb);
        $this->assertStringContainsString('VIETCOMBANK', $vcb->pg_name);
        $this->assertSame('VIETCOMBANK', $vcb->baca_name);

        $scbSacombank = Bank::query()->where('code', 'SCB')->first();
        $this->assertNotNull($scbSacombank);
        $this->assertStringContainsString('SACOMBANK', $scbSacombank->pg_name);
        $this->assertSame('SACOMBANK', $scbSacombank->baca_name);

        $sgcb = Bank::query()->where('code', 'SGCB')->first();
        $this->assertNotNull($sgcb);
        $this->assertSame('SCB', $sgcb->baca_name);
    }
}
