<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BankLookupApi
{
    /**
     * @return array{ok: bool, message: string, banks: list<array{code: string, short_name: string, name: string, bin: string, lookup_supported: bool}>}
     */
    public function listBanks(): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->get('https://api.banklookup.net/bank/list');

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => 'Không thể lấy danh sách ngân hàng từ banklookup.',
                'banks' => [],
            ];
        }

        $payload = $response->json();
        $banks = data_get($payload, 'data', []);
        if (! is_array($banks)) {
            $banks = [];
        }

        $normalized = [];
        foreach ($banks as $bank) {
            if (! is_array($bank)) {
                continue;
            }

            $code = (string) data_get($bank, 'code', '');
            if ($code === '') {
                continue;
            }

            $normalized[] = [
                'code' => $code,
                'short_name' => (string) data_get($bank, 'short_name', data_get($bank, 'shortName', '')),
                'name' => (string) data_get($bank, 'name', ''),
                'bin' => (string) data_get($bank, 'bin', ''),
                'lookup_supported' => (bool) data_get($bank, 'lookup_supported', data_get($bank, 'lookupSupported', true)),
            ];
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'banks' => $normalized,
        ];
    }

    /**
     * @return array{ok: bool, message: string, recipient_name: string}
     */
    public function lookupAccountName(string $bankCode, string $accountNumber): array
    {
        $apiKey = (string) env('BANK_LOOKUP_API_KEY', '');
        $secretKey = (string) env('BANK_LOOKUP_API_SECRET_KEY', '');
        $url = (string) env('BANK_LOOKUP_ACCOUNT_URL', 'https://api.banklookup.net');

        if ($apiKey === '' || $secretKey === '') {
            return [
                'ok' => false,
                'message' => 'Thiếu BANK_LOOKUP_API_KEY / BANK_LOOKUP_API_SECRET_KEY.',
                'recipient_name' => '',
            ];
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'x-api-secret' => $secretKey,
                'Content-Type' => 'application/json',
            ])
            ->asJson()
            ->post($url, [
                'bank' => $bankCode,
                'account' => $accountNumber,
            ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => 'Tra cứu tên người nhận thất bại.',
                'recipient_name' => '',
            ];
        }

        $raw = $response->json();
        $recipientName = $this->extractFirstString($raw, [
            'data.recipient_name',
            'data.account_name',
            'data.accountName',
            'data.ownerName',
            'ownerName',
        ]);
        $recipientName = trim($recipientName);

        return [
            'ok' => $recipientName !== '',
            'message' => (string) ($this->extractFirstString($raw, ['message', 'data.message']) ?: 'OK'),
            'recipient_name' => $recipientName,
        ];
    }

    /**
     * @param  array<mixed>  $raw
     * @param  list<string>  $paths
     */
    private function extractFirstString(array $raw, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($raw, $path);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }
}

