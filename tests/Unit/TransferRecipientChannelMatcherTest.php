<?php

namespace Tests\Unit;

use App\Support\TransferRecipientChannelMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferRecipientChannelMatcherTest extends TestCase
{
    public static function pgInternalCases(): array
    {
        return [
            'code pgbank' => ['PGBANK', '', true],
            'code pg' => ['PG', '', true],
            'name petrolimex' => ['', 'Ngân hàng TMCP Xăng dầu Petrolimex', true],
            'name pg bank' => ['XXX', 'PG BANK', true],
            'external vcb' => ['970436', 'Vietcombank', false],
            'external empty' => ['', '', false],
        ];
    }

    #[DataProvider('pgInternalCases')]
    public function test_is_internal_transfer_pg(string $code, string $name, bool $expected): void
    {
        $payload = ['bank_code' => $code, 'bank_name' => $name];
        $this->assertSame(
            $expected,
            TransferRecipientChannelMatcher::isInternalTransfer('pg_transfer', $payload),
        );
    }

    public static function bacaInternalCases(): array
    {
        return [
            'code bab' => ['BAB', '', true],
            'code bacabank' => ['BACABANK', '', true],
            'name bac a' => ['', 'Ngân hàng TMCP Bắc Á', true],
            'name nam a bank' => ['', 'NAM A BANK', true],
            'external pg' => ['PGBANK', 'PG Bank', false],
        ];
    }

    #[DataProvider('bacaInternalCases')]
    public function test_is_internal_transfer_baca(string $code, string $name, bool $expected): void
    {
        $payload = ['bank_code' => $code, 'bank_name' => $name];
        $this->assertSame(
            $expected,
            TransferRecipientChannelMatcher::isInternalTransfer('baca_transfer', $payload),
        );
    }

    public function test_non_transfer_types_return_false(): void
    {
        $payload = ['bank_code' => 'PGBANK', 'bank_name' => 'PG'];
        $this->assertFalse(TransferRecipientChannelMatcher::isInternalTransfer('pg_check_login', $payload));
    }
}
