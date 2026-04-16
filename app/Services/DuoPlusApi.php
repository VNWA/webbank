<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DuoPlusApi
{
    /**
     * Bật livestream (quét mặt) cho cloud phone.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function startLivestream(string $apiKey, string $imageId, string $videoId): array
    {
        return $this->post($apiKey, '/api/v1/cloudPhone/live', [
            'image_id' => $imageId,
            'id' => $videoId,
            'status' => 1,
            'loop' => 1,
        ]);
    }

    /**
     * Gọi batch power on theo docs DuoPlus; `ok` chỉ true khi `image_id` nằm trong `data.success`.
     *
     * @see https://help.duoplus.net/docs/batch-power-on
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function powerOn(string $apiKey, string $imageId): array
    {
        $result = $this->post($apiKey, '/api/v1/cloudPhone/powerOn', [
            'image_ids' => [$imageId],
        ]);

        return $this->normalizeBatchPowerResult($result, $imageId);
    }

    /**
     * Gọi batch power off theo docs DuoPlus; `ok` chỉ true khi `image_id` nằm trong `data.success`.
     *
     * @see https://help.duoplus.net/docs/batch-power-off
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function powerOff(string $apiKey, string $imageId): array
    {
        $result = $this->post($apiKey, '/api/v1/cloudPhone/powerOff', [
            'image_ids' => [$imageId],
        ]);

        return $this->normalizeBatchPowerResult($result, $imageId);
    }

    /**
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function status(string $apiKey, string $imageId): array
    {
        return $this->post($apiKey, '/api/v1/cloudPhone/status', [
            'image_ids' => [$imageId],
        ]);
    }

    /**
     * Chạy lệnh ADB qua DWIN cloud phone command API.
     *
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    public function command(string $apiKey, string $imageId, string $command): array
    {
        // Screencap + base64 có thể rất lớn; 60s đôi khi không đủ hoặc bị cắt phản hồi.
        $result = $this->post($apiKey, '/api/v1/cloudPhone/command', [
            'image_id' => $imageId,
            'command' => $command,
        ], 120);

        if (! $result['ok']) {
            return $result;
        }

        $success = data_get($result['data'], 'data.success');
        if ($success === false) {
            return [
                'ok' => false,
                'message' => 'DWIN command trả về success=false.',
                'data' => $result['data'],
            ];
        }

        return $result;
    }

    /**
     * Đọc trạng thái máy ảo theo `POST /api/v1/cloudPhone/status`, map `data.list[].status` (int) sang chuỗi dùng trong app.
     *
     * @see https://help.duoplus.net/docs/cloud-phone-status
     */
    public function liveDeviceStatusLabel(string $apiKey, string $imageId): string
    {
        $result = $this->status($apiKey, $imageId);

        if (! $result['ok']) {
            return 'unknown';
        }

        $code = $this->extractCloudPhoneStatusCode($result['data'], $imageId);

        return $this->mapCloudPhoneStatusCode($code);
    }

    /**
     * @param  array<string, mixed>  $json  Payload JSON gốc (có `code`, `data`, …)
     */
    public function extractCloudPhoneStatusCode(array $json, string $imageId): ?int
    {
        $list = data_get($json, 'data.list');
        if (! is_array($list)) {
            return null;
        }

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $rowId = (string) data_get($item, 'id', data_get($item, 'image_id', ''));
            if ($rowId !== $imageId) {
                continue;
            }

            $raw = data_get($item, 'status');
            if (is_int($raw)) {
                return $raw;
            }

            if (is_numeric($raw)) {
                return (int) $raw;
            }

            return null;
        }

        return null;
    }

    /**
     * Map theo bảng trạng thái DWIN (0–12).
     */
    public function mapCloudPhoneStatusCode(?int $code): string
    {
        return match ($code) {
            0 => 'not_configured',
            1 => 'on',
            2 => 'off',
            3 => 'expired',
            4 => 'renewal_needed',
            10 => 'powering_on',
            11 => 'configuring',
            12 => 'config_failed',
            default => 'unknown',
        };
    }

    /**
     * @param  array{ok: bool, message: string, data: array<string, mixed>}  $result
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    private function normalizeBatchPowerResult(array $result, string $imageId): array
    {
        if (! $result['ok']) {
            return $result;
        }

        $payload = $result['data'];
        $success = data_get($payload, 'data.success');
        $fail = data_get($payload, 'data.fail');
        $successList = is_array($success) ? $success : [];
        $failList = is_array($fail) ? $fail : [];

        $inSuccess = $this->listContainsId($successList, $imageId);
        $inFail = $this->listContainsId($failList, $imageId);

        if ($inSuccess) {
            return [
                'ok' => true,
                'message' => $result['message'] !== '' ? $result['message'] : 'Success',
                'data' => $payload,
            ];
        }

        if ($inFail) {
            return [
                'ok' => false,
                'message' => 'DWIN từ chối power cho image_id này (nằm trong danh sách fail).',
                'data' => $payload,
            ];
        }

        return [
            'ok' => false,
            'message' => 'Không xác định được kết quả power (image_id không có trong success/fail).',
            'data' => $payload,
        ];
    }

    /**
     * @param  list<mixed>  $list
     */
    private function listContainsId(array $list, string $imageId): bool
    {
        foreach ($list as $item) {
            if ((string) $item === $imageId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, data: array<string, mixed>}
     */
    private function post(string $apiKey, string $path, array $payload, int $timeoutSeconds = 60): array
    {
        $response = Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->withHeaders([
                'Lang' => 'zh',
                'DuoPlus-API-Key' => $apiKey,
            ])
            ->asJson()
            ->post('https://openapi.duoplus.net'.$path, $payload);

        $json = $response->json();
        $isOk = $response->successful() && is_array($json) && (int) data_get($json, 'code') === 200;
        $message = (string) data_get($json, 'message', data_get($json, 'msg', ''));

        return [
            'ok' => $isOk,
            'message' => $message,
            'data' => is_array($json) ? $json : [],
        ];
    }
}
