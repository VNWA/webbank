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
            'code pgb napas' => ['PGB', '', true],
            'code pgb spaced' => [' pgb ', '', true],
            'external vcb' => ['VCB', 'Vietcombank', false],
            'external empty' => ['', '', false],
            'old pgbank string' => ['PGBANK', '', false],
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
            'external pg code' => ['PGB', 'PG Bank', false],
            'external vcb' => ['VCB', '', false],
            'name only ignored' => ['', 'NAM A BANK', false],
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
        $payload = ['bank_code' => 'PGB', 'bank_name' => 'PG'];
        $this->assertFalse(TransferRecipientChannelMatcher::isInternalTransfer('pg_check_login', $payload));
    }
}
