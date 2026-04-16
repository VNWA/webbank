<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BankLookupApi
{
    private string $baseUrl;

    private string $apiKey;

    private string $secretKey;

    private int $timeout;

    public function __construct()
    {
        $cfg = (array) config('banklookup');
        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.banklookup.net'), '/');
        $this->apiKey = (string) ($cfg['api_key'] ?? '');
        $this->secretKey = (string) ($cfg['secret_key'] ?? '');
        $this->timeout = (int) ($cfg['timeout'] ?? 20);
    }

    /**
     * @return array{ok: bool, message: string, banks: list<array{code: string, short_name: string, name: string, bin: string, lookup_supported: bool}>}
     */
    public function listBanks(): array
    {
        $path = (string) (config('banklookup.bank_list_path') ?? '/bank/list');

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->get($this->baseUrl.$path);

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
        if ($this->apiKey === '' || $this->secretKey === '') {
            return [
                'ok' => false,
                'message' => 'Thiếu BANK_LOOKUP_API_KEY / BANK_LOOKUP_API_SECRET_KEY trong config.',
                'recipient_name' => '',
            ];
        }

        $path = (string) (config('banklookup.account_path') ?? '/api/account-name');

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'x-api-secret' => $this->secretKey,
                'Content-Type' => 'application/json',
            ])
            ->asJson()
            ->post($this->baseUrl.$path, [
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
