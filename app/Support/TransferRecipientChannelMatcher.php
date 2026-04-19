<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Chuyển nội bộ trên app khi NH nhận trùng NH vận hành kênh: mã trong DB `banks.code` — PG = PGB, Bắc Á = BAB.
 *
 * @param  array<string, mixed>  $payload  operation_payload (`bank_code` từ model Bank)
 */
final class TransferRecipientChannelMatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function isInternalTransfer(string $operationType, array $payload): bool
    {
        $code = (string) ($payload['bank_code'] ?? '');

        return match ($operationType) {
            'pg_transfer' => self::matchesPgOwnBank($code),
            'baca_transfer' => self::matchesBacaOwnBank($code),
            default => false,
        };
    }

    public static function matchesPgOwnBank(string $code): bool
    {
        return self::normalizeToken($code) === 'PGB';
    }

    public static function matchesBacaOwnBank(string $code): bool
    {
        return self::normalizeToken($code) === 'BAB';
    }

    private static function normalizeToken(string $s): string
    {
        $ascii = Str::ascii($s);
        $upper = Str::upper($ascii);

        return preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    }
}
