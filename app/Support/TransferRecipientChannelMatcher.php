<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * So khớp ngân hàng nhận với kênh PG / Bắc Á để biết chuyển nội bộ (cùng NH) hay không.
 */
final class TransferRecipientChannelMatcher
{
    /**
     * @param  array<string, mixed>  $payload  operation_payload (bank_code, bank_name, …)
     */
    public static function isInternalTransfer(string $operationType, array $payload): bool
    {
        $code = (string) ($payload['bank_code'] ?? '');
        $name = (string) ($payload['bank_name'] ?? '');

        return match ($operationType) {
            'pg_transfer' => self::matchesPgOwnBank($code, $name),
            'baca_transfer' => self::matchesBacaOwnBank($code, $name),
            default => false,
        };
    }

    public static function matchesPgOwnBank(string $code, string $name): bool
    {
        $cfg = (array) config('pgbank.own_bank_match', []);
        $codes = array_map(static fn (mixed $x): string => self::normalizeToken((string) $x), (array) ($cfg['codes'] ?? ['PGBANK']));
        $keywords = (array) ($cfg['name_keywords'] ?? []);

        $nCode = self::normalizeToken($code);
        foreach ($codes as $c) {
            $c = self::normalizeToken($c);
            if ($c !== '' && ($nCode === $c || str_contains($nCode, $c))) {
                return true;
            }
        }

        $blob = self::normalizeBlob($name);
        foreach ($keywords as $kw) {
            $k = self::normalizeBlob((string) $kw);
            if ($k !== '' && str_contains($blob, $k)) {
                return true;
            }
        }

        return false;
    }

    public static function matchesBacaOwnBank(string $code, string $name): bool
    {
        $cfg = (array) config('bacabank.own_bank_match', []);
        $codes = array_map(static fn (mixed $x): string => self::normalizeToken((string) $x), (array) ($cfg['codes'] ?? ['BACABANK', 'BAB']));
        $keywords = (array) ($cfg['name_keywords'] ?? []);

        $nCode = self::normalizeToken($code);
        foreach ($codes as $c) {
            $c = self::normalizeToken($c);
            if ($c !== '' && ($nCode === $c || str_contains($nCode, $c))) {
                return true;
            }
        }

        $blob = self::normalizeBlob($name);
        foreach ($keywords as $kw) {
            $k = self::normalizeBlob((string) $kw);
            if ($k !== '' && str_contains($blob, $k)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeBlob(string $s): string
    {
        $ascii = Str::ascii($s);
        $upper = Str::upper($ascii);

        return preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    }

    private static function normalizeToken(string $s): string
    {
        return self::normalizeBlob($s);
    }
}
