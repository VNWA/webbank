<?php

namespace App\Jobs;

use App\Events\DeviceOperationUpdated;
use App\Models\Device;
use App\Models\DeviceOperation;
use App\Models\DeviceOperationLog;
use App\Services\DuoPlusApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDeviceOperation implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $operationId) {}

    public function handle(DuoPlusApi $duoPlusApi): void
    {
        $operation = DeviceOperation::query()
            ->with('device')
            ->find($this->operationId);

        if (! $operation || ! $operation->device) {
            return;
        }

        $operation->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'result_message' => null,
        ]);
        $this->broadcast($operation);

        try {
            $result = match ($operation->operation_type) {
                'pg_check_login' => $this->runPgCheckLogin($operation->device, $duoPlusApi, $operation),
                'baca_check_login' => $this->runBacaCheckLogin($operation->device, $duoPlusApi, $operation),
                default => ['ok' => false, 'message' => 'Loại lệnh không được hỗ trợ.'],
            };

            $operation->update([
                'status' => $result['ok'] ? 'success' : 'failed',
                'result_message' => $result['message'],
                'finished_at' => now(),
            ]);
            $this->broadcast($operation);
        } catch (Throwable $exception) {
            $this->log($operation, 'exception', $exception->getMessage(), 'error', [
                'trace' => str($exception->getTraceAsString())->limit(1000)->value(),
            ]);

            $operation->update([
                'status' => 'failed',
                'result_message' => 'Lệnh lỗi hệ thống: '.$exception->getMessage(),
                'finished_at' => now(),
            ]);
            $this->broadcast($operation);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function runPgCheckLogin(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $package = 'pgbankApp.pgbank.com.vn';

        $this->log($operation, 'start', 'Bắt đầu PG check login.');
        $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop', "am force-stop {$package}");
        $this->sendAdb($duoPlusApi, $device, $operation, 'sleep_short', 'sleep 0.35');
        $this->sendAdb($duoPlusApi, $device, $operation, 'open_app', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_open', 'sleep 4');
        $this->sendAdb($duoPlusApi, $device, $operation, 'focus_password', 'input tap 232 939');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_password', $this->inputTextCommand($device->pg_pass));
        $this->sendAdb($duoPlusApi, $device, $operation, 'tap_login', 'input tap 340 1145');
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_after_login', 'sleep 3');

        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);

        if (str_contains($dump, 'com.android.vending')) {
            $this->log($operation, 'play_store', 'Phát hiện Play Store, xử lý quay lại.');
            $this->sendAdb($duoPlusApi, $device, $operation, 'back_from_play_store', 'input keyevent 4');
            $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop_play_store', 'am force-stop com.android.vending');
            $this->sendAdb($duoPlusApi, $device, $operation, 'reopen_pg', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
            $this->sendAdb($duoPlusApi, $device, $operation, 'wait_reopen', 'sleep 3');
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
        }

        if (str_contains(mb_strtolower($dump), 'bỏ qua')) {
            $this->log($operation, 'skip_button', 'Phát hiện nút Bỏ qua, tự động nhấn.');
            $this->sendAdb($duoPlusApi, $device, $operation, 'tap_skip', 'input tap 241 1834');
            $this->sendAdb($duoPlusApi, $device, $operation, 'wait_skip', 'sleep 1');
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
        }

        $markers = [
            'xin chào',
            'xin chao',
            'tài khoản',
            'tai khoan',
            'tài khoản thanh toán',
            'tai khoan thanh toan',
            'chuyển tiền',
            'chuyen tien',
            'chuyển khoản',
            'chuyen khoan',
            'pgbank',
        ];

        $ok = $this->containsAny($dump, $markers);
        if (! $ok) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'wait_login_retry_1', 'sleep 2');
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->containsAny($dump, $markers);
        }

        if (! $ok) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'wait_login_retry_2', 'sleep 3');
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->containsAny($dump, $markers);
        }

        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        if (! $ok) {
            $this->log($operation, 'login_marker_debug', 'Không match marker sau khi retry.', 'warning', [
                'markers' => $markers,
                'dump_preview' => str($dump)->limit(500)->value(),
            ]);

            return [
                'ok' => false,
                'message' => 'PG check login thất bại: không thấy marker màn hình chính.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'PG check login thành công.',
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function runBacaCheckLogin(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $package = 'com.bab.retailUAT';

        $this->log($operation, 'start', 'Bắt đầu Bắc Á check login.');
        $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop', "am force-stop {$package}");
        $this->sendAdb($duoPlusApi, $device, $operation, 'sleep_short', 'sleep 0.35');
        $this->sendAdb($duoPlusApi, $device, $operation, 'open_app', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_open', 'sleep 3');
        $this->sendAdb($duoPlusApi, $device, $operation, 'focus_password', 'input tap 193 839');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_password', $this->inputTextCommand($device->baca_pass));
        $this->sendAdb($duoPlusApi, $device, $operation, 'tap_login', 'input tap 301 1005');
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_after_login', 'sleep 3');

        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
        $this->sendAdb($duoPlusApi, $device, $operation, 'reveal_balance', 'input tap 956 967');
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_reveal_balance', 'sleep 1');
        $dumpAfterReveal = $this->dumpUiText($duoPlusApi, $device, $operation);

        $ok = $this->containsAny($dumpAfterReveal !== '' ? $dumpAfterReveal : $dump, ['tài khoản thanh toán']);
        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        if (! $ok) {
            return [
                'ok' => false,
                'message' => 'Bắc Á check login thất bại: không thấy marker tài khoản.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Bắc Á check login thành công.',
        ];
    }

    private function sendAdb(
        DuoPlusApi $duoPlusApi,
        Device $device,
        DeviceOperation $operation,
        string $stage,
        string $command,
    ): void {
        $result = $duoPlusApi->command($device->duo_api_key, $device->image_id, $command);

        if (! $result['ok']) {
            $message = $result['message'] !== '' ? $result['message'] : 'DuoPlus command lỗi.';
            $this->log($operation, $stage, $message, 'error', ['command' => $command]);

            throw new \RuntimeException($message);
        }

        $this->log($operation, $stage, 'OK', 'info', ['command' => $command]);
    }

    private function dumpUiText(DuoPlusApi $duoPlusApi, Device $device, DeviceOperation $operation): string
    {
        $result = $duoPlusApi->command($device->duo_api_key, $device->image_id, 'uiautomator dump /dev/tty');

        if (! $result['ok']) {
            $this->log($operation, 'dump_ui', $result['message'] !== '' ? $result['message'] : 'Dump UI lỗi.', 'error');

            return '';
        }

        $content = (string) data_get($result['data'], 'data.content', '');
        $this->log($operation, 'dump_ui', 'Đã dump UI thành công.', 'info', [
            'preview' => str($content)->limit(300)->value(),
            'length' => mb_strlen($content),
        ]);

        return mb_strtolower($content);
    }

    private function inputTextCommand(string $raw): string
    {
        $normalized = preg_replace('/\s+/', '%s', $raw) ?? '';
        $escaped = preg_replace('/([\\\\\"\'`$;&|<>])/', '\\\\$1', $normalized) ?? '';

        return 'input text '.$escaped;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function log(
        DeviceOperation $operation,
        string $stage,
        string $message,
        string $level = 'info',
        ?array $meta = null,
    ): void {
        DeviceOperationLog::query()->create([
            'device_operation_id' => $operation->id,
            'level' => $level,
            'stage' => $stage,
            'message' => $message,
            'meta' => $meta,
        ]);
        $this->broadcast($operation);
    }

    private function broadcast(DeviceOperation $operation): void
    {
        $payload = $operation->fresh()->load(['logs', 'requester:id,name']);
        if (! $payload) {
            return;
        }

        DeviceOperationUpdated::dispatch([
            'id' => $payload->id,
            'device_id' => $payload->device_id,
            'requested_by' => $payload->requested_by,
            'requested_by_name' => $payload->requester?->name,
            'operation_type' => $payload->operation_type,
            'status' => $payload->status,
            'result_message' => $payload->result_message,
            'started_at' => $payload->started_at?->toIso8601String(),
            'finished_at' => $payload->finished_at?->toIso8601String(),
            'created_at' => $payload->created_at?->toIso8601String(),
            'logs' => $payload->logs->map(fn ($log): array => [
                'id' => $log->id,
                'level' => $log->level,
                'stage' => $log->stage,
                'message' => $log->message,
                'meta' => $log->meta,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
