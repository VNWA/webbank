<?php

namespace App\Jobs;

use App\Events\DeviceOperationUpdated;
use App\Models\Device;
use App\Models\DeviceOperation;
use App\Models\DeviceOperationLog;
use App\Services\DuoPlusApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

class ProcessDeviceOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(public int $operationId)
    {
        $this->onQueue('devices');
    }

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
                'pg_balance' => $this->runPgBalance($operation->device, $duoPlusApi, $operation),
                'baca_balance' => $this->runBacaBalance($operation->device, $duoPlusApi, $operation),
                'pg_transfer' => $this->runPgTransfer($operation->device, $duoPlusApi, $operation),
                'baca_transfer' => $this->runBacaTransfer($operation->device, $duoPlusApi, $operation),
                'baca_test_pin' => $this->runBacaTestPin($operation->device, $duoPlusApi, $operation),
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

    public function failed(?Throwable $exception = null): void
    {
        $operation = DeviceOperation::query()->find($this->operationId);
        if (! $operation) {
            return;
        }

        $message = $exception?->getMessage() ?: 'Job failed.';

        $operation->update([
            'status' => 'failed',
            'result_message' => str_starts_with($message, 'Lệnh lỗi hệ thống:')
                ? $message
                : 'Lệnh lỗi hệ thống: '.$message,
            'finished_at' => now(),
        ]);

        try {
            $this->broadcast($operation);
        } catch (Throwable $e) {
            Log::warning('Broadcast failed in job failed()', [
                'operation_id' => $operation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function runPgCheckLogin(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $package = 'pgbankApp.pgbank.com.vn';
        $cfg = (array) config('pgbank');
        $tapCfg = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);

        $this->log($operation, 'start', 'Bắt đầu PG check login.');
        $this->ensurePgLogin($device, $duoPlusApi, $operation, $package, $tapCfg, $timing);

        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);

        if (str_contains($dump, 'com.android.vending')) {
            $this->log($operation, 'play_store', 'Phát hiện Play Store, xử lý quay lại.');
            $this->sendAdb($duoPlusApi, $device, $operation, 'back_from_play_store', 'input keyevent 4');
            $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop_play_store', 'am force-stop com.android.vending');
            $this->sendAdb($duoPlusApi, $device, $operation, 'reopen_pg', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
            $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_reopen', (float) ($timing['wait_open'] ?? 4.0));
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
        $cfg = (array) config('bacabank');
        $tapCfg = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);

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
        $reveal = $tapCfg['reveal_balance'] ?? [968, 1003];
        if (is_array($reveal)) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'reveal_balance', 'input tap '.$reveal[0].' '.$reveal[1]);
        }
        $this->sendAdb(
            $duoPlusApi,
            $device,
            $operation,
            'wait_reveal_balance',
            'sleep '.number_format((float) ($timing['wait_reveal_balance'] ?? 1.3), 2, '.', ''),
        );
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

    /**
     * @return array{ok: bool, message: string}
     */
    private function runBacaBalance(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $package = 'com.bab.retailUAT';
        $cfg = (array) config('bacabank');
        $tapCfg = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);

        $this->log($operation, 'start', 'Bắt đầu Bắc Á check số dư.');
        $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop', "am force-stop {$package}");
        $this->sendAdb($duoPlusApi, $device, $operation, 'sleep_short', 'sleep 0.35');
        $this->sendAdb($duoPlusApi, $device, $operation, 'open_app', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_open', 'sleep 3');
        $this->sendAdb($duoPlusApi, $device, $operation, 'focus_password', 'input tap 193 839');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_password', $this->inputTextCommand($device->baca_pass));
        $this->sendAdb($duoPlusApi, $device, $operation, 'tap_login', 'input tap 301 1005');
        $this->sendAdb($duoPlusApi, $device, $operation, 'wait_after_login', 'sleep 3');

        // Tap để hiện số dư (giống flow check_login).
        $reveal = $tapCfg['reveal_balance'] ?? [968, 1003];
        if (is_array($reveal)) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'reveal_balance', 'input tap '.$reveal[0].' '.$reveal[1]);
        }
        $this->sendAdb(
            $duoPlusApi,
            $device,
            $operation,
            'wait_reveal_balance',
            'sleep '.number_format((float) ($timing['wait_reveal_balance'] ?? 1.3), 2, '.', ''),
        );
        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);

        $balance = $this->parseBalanceFromDump($dump);
        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        if ($balance === null) {
            $this->log($operation, 'balance_parse_failed', 'Không parse được số dư từ UI dump.', 'warning', [
                'dump_preview' => str($dump)->limit(500)->value(),
            ]);

            return [
                'ok' => false,
                'message' => 'Bắc Á check số dư thất bại: không đọc được số dư.',
            ];
        }

        $device->forceFill([
            'baca_balance' => $balance,
            'baca_balance_updated_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'message' => 'Bắc Á số dư: '.number_format($balance, 0, ',', '.').' VND',
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function runPgBalance(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $package = 'pgbankApp.pgbank.com.vn';
        $cfg = (array) config('pgbank');
        $tapCfg = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);

        $this->log($operation, 'start', 'Bắt đầu PG check số dư.');

        // Login
        $this->ensurePgLogin($device, $duoPlusApi, $operation, $package, $tapCfg, $timing);

        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);

        // Verify login
        $markers = ['xin chào', 'xin chao', 'tài khoản', 'tai khoan', 'chuyển tiền', 'chuyen tien', 'pgbank'];
        if (! $this->containsAny($dump, $markers)) {
            $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_login_retry', 3.0);
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
            if (! $this->containsAny($dump, $markers)) {
                $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

                return ['ok' => false, 'message' => 'PG check số dư thất bại: không đăng nhập được.'];
            }
        }

        // botBank: tap account_tap (539,1247) → sleep 2s → dump UI to get balance
        $accountTap = $tapCfg['account_balance'] ?? [539, 1247];
        if (is_array($accountTap)) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_account_balance', $accountTap);
        }
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_account_balance', 2.0);

        $dump2 = $this->dumpUiText($duoPlusApi, $device, $operation);

        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        $balance = $this->parseBalanceFromDump($dump2);
        if ($balance === null) {
            $this->log($operation, 'balance_parse_failed', 'Không parse được số dư từ UI dump.', 'warning', [
                'dump_preview' => str($dump2)->limit(500)->value(),
            ]);

            return ['ok' => false, 'message' => 'PG check số dư thất bại: không đọc được số dư.'];
        }

        $device->forceFill([
            'pg_balance' => $balance,
            'pg_balance_updated_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'message' => 'PG số dư: '.number_format($balance, 0, ',', '.').' VND',
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function runBacaTransfer(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $payload = is_array($operation->operation_payload) ? $operation->operation_payload : [];
        $bankName = (string) ($payload['bank_name'] ?? '');
        $account = (string) ($payload['account_number'] ?? $payload['account'] ?? '');
        $recipientName = (string) ($payload['recipient_name'] ?? '');
        $note = (string) ($payload['content'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);

        if ($account === '' || $amount <= 0) {
            return ['ok' => false, 'message' => 'Thiếu dữ liệu chuyển tiền (STK / số tiền).'];
        }

        $cfg = (array) config('bacabank');
        $tap = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);
        $flow = (array) ($cfg['flow'] ?? []);

        $bankNameForSearch = $this->resolveBankNameForSearch($bankName, (array) ($cfg['bank_name_map'] ?? []), (array) ($cfg['bank_list'] ?? []));

        $package = 'com.bab.retailUAT';

        $this->log($operation, 'start', 'Bắt đầu Bắc Á chuyển tiền.', 'info', [
            'bank_input' => $bankName,
            'bank_resolved' => $bankNameForSearch,
        ]);
        $this->ensureBacaLogin($device, $duoPlusApi, $operation, $package, $tap, $timing);

        // Phase A: vào chuyển tiền + TK khác
        $this->tap($duoPlusApi, $device, $operation, 'tap_transfer', $tap['transfer']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_transfer', 1.5);
        $this->tap($duoPlusApi, $device, $operation, 'tap_other_account', $tap['other_account']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_other_account', 1.5);

        // STK
        $this->tap($duoPlusApi, $device, $operation, 'focus_account', $tap['account']);
        $this->sendAdb($duoPlusApi, $device, $operation, 'clear_account', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_account', $this->inputTextCommand($account));
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_account', 1.0);

        // Bank select
        $this->tap($duoPlusApi, $device, $operation, 'tap_bank', $tap['bank']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_bank', (float) ($timing['wait_bank_open'] ?? 1.0));
        $this->tap($duoPlusApi, $device, $operation, 'tap_bank_search', $tap['bank_search']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_bank_search', (float) ($timing['wait_bank_search_focus'] ?? 0.3));
        $this->sendAdb(
            $duoPlusApi,
            $device,
            $operation,
            'input_bank_name',
            $this->inputTextCommand($bankNameForSearch !== '' ? $bankNameForSearch : $bankName),
        );
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_bank_typing', (float) ($timing['wait_bank_typing'] ?? 1.8));
        $this->tap($duoPlusApi, $device, $operation, 'tap_bank_first_row', $tap['bank_first_row']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_bank_selected', (float) ($timing['wait_bank_selected'] ?? 1.8));

        // Sau khi chọn bank+stk xong (flow bạn chốt): tap after_bank rồi tiếp tục.
        if (isset($tap['after_bank']) && is_array($tap['after_bank'])) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_after_bank', $tap['after_bank']);
            $this->pauseLocal((float) ($timing['wait_after_bank_selected'] ?? 2.0));
        }

        // Optional: bấm Kiểm tra thụ hưởng nếu bật config.
        if (($flow['use_check_beneficiary'] ?? false) && isset($tap['check_beneficiary']) && is_array($tap['check_beneficiary'])) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_check_beneficiary', $tap['check_beneficiary']);
            $this->pauseLocal((float) ($timing['wait_after_check'] ?? 2.0));
        }

        // Continue step 1
        $this->tap($duoPlusApi, $device, $operation, 'continue_step_1', $tap['continue_step_1']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_continue_step_1', (float) ($timing['wait_after_continue_1'] ?? 1.0));

        // Amount + note
        $this->tap($duoPlusApi, $device, $operation, 'focus_amount', $tap['amount']);
        $this->sendAdb($duoPlusApi, $device, $operation, 'clear_amount', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_amount', $this->inputTextCommand((string) $amount));
        $this->pauseLocal((float) ($timing['wait_after_amount'] ?? 1.0));
        $this->tap($duoPlusApi, $device, $operation, 'blur_amount', $tap['blur_amount']);
        $this->pauseLocal((float) ($timing['wait_after_blur'] ?? 0.5));

        // Note: tap focus → keyevent 277 (select all) → tap clear_note → input text
        $safeNote = $this->normalizeTransferNote($note !== '' ? $note : 'ck');
        $this->tap($duoPlusApi, $device, $operation, 'focus_note', $tap['note']);
        $this->pauseLocal(0.25);
        $this->sendAdb($duoPlusApi, $device, $operation, 'select_note', 'input keyevent 277');
        if (isset($tap['clear_note']) && is_array($tap['clear_note'])) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_clear_note', $tap['clear_note']);
            $this->pauseLocal(0.15);
        }
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_note', $this->inputTextCommand($safeNote));
        $this->pauseLocal(0.5);
        $this->tap($duoPlusApi, $device, $operation, 'blur_note', $tap['blur_note']);
        $this->pauseLocal((float) ($timing['wait_after_blur'] ?? 0.5));

        // Continue step 2 (twice)
        $this->tap($duoPlusApi, $device, $operation, 'continue_step_2', $tap['continue_step_2']);
        $this->pauseLocal(1.0);
        $this->tap($duoPlusApi, $device, $operation, 'continue_step_2_again', $tap['continue_step_2']);
        $this->pauseLocal((float) ($timing['wait_after_continue_2'] ?? 1.5));

        // Livestream + face scan if >= threshold
        $threshold = (int) ($cfg['face_scan_threshold'] ?? 10_000_000);
        $didFaceScan = false;
        if ($amount >= $threshold) {
            if ((string) $device->baca_video_id === '') {
                return ['ok' => false, 'message' => 'Thiếu `baca_video_id` để bật livestream (>= 10tr).'];
            }
            $this->log($operation, 'livestream', 'Bật livestream để quét mặt (>=10tr).');
            $live = $duoPlusApi->startLivestream($device->duo_api_key, $device->image_id, (string) $device->baca_video_id);
            if (! $live['ok']) {
                return ['ok' => false, 'message' => 'Không bật được livestream: '.$live['message']];
            }
            $this->pauseLocal((float) ($timing['wait_face_scan'] ?? 2.0));
            $didFaceScan = true;
        }

        // OTP / Smart PIN entry
        $pin = preg_replace('/\D+/', '', (string) $device->baca_pin) ?? '';
        if (strlen($pin) !== 6) {
            return ['ok' => false, 'message' => 'PIN Bắc Á không hợp lệ (cần 6 số).'];
        }

        $waitBeforeOtp = (float) ($timing['wait_before_otp'] ?? 0.6);
        // Đã chờ quét mặt xong 2s rồi (botBank), nên không cộng thêm delay trước OTP.
        if ($didFaceScan) {
            $waitBeforeOtp = 0.0;
        }
        $this->pauseLocal($waitBeforeOtp);
        $digits = (array) ($cfg['numpad_digits'] ?? []);

        $focus = $tap['otp_focus'] ?? null;
        for ($i = 0; $i < 6; $i++) {
            $d = $pin[$i];
            $xy = $digits[$d] ?? null;
            if (! is_array($xy)) {
                return ['ok' => false, 'message' => 'Thiếu tọa độ numpad cho digit '.$d];
            }
            // botBank: trước mỗi số tap vùng focus (540,1180) rồi mới tap numpad digit.
            if (is_array($focus)) {
                $this->tap($duoPlusApi, $device, $operation, 'otp_focus_'.$i, $focus);
                $this->pauseLocal((float) ($timing['wait_otp_focus'] ?? 0.12));
            }
            $this->tap($duoPlusApi, $device, $operation, "otp_digit_{$d}", $xy);
            $this->pauseLocal((float) ($timing['wait_otp_digit'] ?? 0.28));
        }
        $this->pauseLocal((float) ($timing['wait_after_otp_digits'] ?? 0.5));

        // Confirm
        $this->tap($duoPlusApi, $device, $operation, 'tap_confirm', $tap['confirm']);
        $this->pauseLocal((float) ($timing['wait_after_confirm'] ?? 3.0));

        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
        $ok = $this->containsAny($dump, ['thành công', 'thanh cong', 'giao dịch thành công', 'chuyển tiền thành công']);
        if (! $ok) {
            $this->pauseLocal((float) ($timing['wait_after_success_retry'] ?? 3.0));
            $dump2 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->containsAny($dump2, ['thành công', 'thanh cong', 'giao dịch thành công', 'chuyển tiền thành công']);
        }

        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        if (! $ok) {
            return [
                'ok' => false,
                'message' => 'Bắc Á chuyển tiền thất bại (không thấy marker thành công).',
            ];
        }

        // Update cached balance (best-effort)
        if ($device->baca_balance !== null) {
            $device->forceFill([
                'baca_balance' => max(0, (float) $device->baca_balance - $amount),
                'baca_balance_updated_at' => now(),
            ])->save();
        }

        return [
            'ok' => true,
            'message' => $this->appendReceiptUrl(
                operation: $operation,
                channel: 'baca',
                baseMessage: 'Bắc Á chuyển tiền thành công'.($recipientName !== '' ? " ({$recipientName})" : '').'.',
                duoPlusApi: $duoPlusApi,
                device: $device,
            ),
        ];
    }

    /**
     * Chạy test để calibrate tọa độ numpad: nhập `baca_pin` (6 số) nhưng KHÔNG bấm xác nhận.
     * Trả về link ảnh sau khi nhập để bạn nhìn xem số đã đúng chưa.
     *
     * @return array{ok: bool, message: string}
     */
    private function runBacaTestPin(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $cfg = (array) config('bacabank');
        $tap = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);
        $digits = (array) ($cfg['numpad_digits'] ?? []);

        $package = 'com.bab.retailUAT';

        $pin = preg_replace('/\D+/', '', (string) $device->baca_pin) ?? '';
        if (strlen($pin) !== 6) {
            return ['ok' => false, 'message' => 'PIN Bắc Á không hợp lệ (cần 6 số).'];
        }

        $this->log($operation, 'start', 'Test PIN Bắc Á (calibrate numpad).');
        $this->ensureBacaLogin($device, $duoPlusApi, $operation, $package, $tap, $timing);

        // Vào chuyển tiền để đảm bảo có màn OTP (nhanh nhất là đi như flow chuyển tiền tới đoạn OTP là quá dài)
        // -> Ở đây chỉ chụp ảnh để bạn tự mở màn OTP trước khi test, nên yêu cầu bạn mở sẵn màn OTP.
        // Nếu chưa ở màn OTP, test vẫn tap numpad nhưng không có tác dụng.

        $this->pauseLocal((float) ($timing['wait_before_otp'] ?? 0.6));

        $focus = $tap['otp_focus'] ?? null;
        for ($i = 0; $i < 6; $i++) {
            $d = $pin[$i];
            $xy = $digits[$d] ?? null;
            if (! is_array($xy)) {
                return ['ok' => false, 'message' => 'Thiếu tọa độ numpad cho digit '.$d];
            }
            // botBank: trước mỗi số tap vùng focus (540,1180) rồi mới tap numpad digit.
            if (is_array($focus)) {
                $this->tap($duoPlusApi, $device, $operation, 'otp_focus_'.$i, $focus);
                $this->pauseLocal((float) ($timing['wait_otp_focus'] ?? 0.12));
            }
            $this->log($operation, "otp_digit_{$d}", 'tap', 'info', ['xy' => $xy]);
            $this->tap($duoPlusApi, $device, $operation, "otp_digit_tap_{$d}", $xy);
            $this->pauseLocal((float) ($timing['wait_otp_digit'] ?? 0.28));
        }

        $url = $this->captureAndStoreDebugScreenshot($operation, 'baca-pin', $duoPlusApi, $device);
        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        return [
            'ok' => true,
            'message' => 'Đã test nhập PIN Bắc Á. Ảnh: '.($url ?? '(không chụp được ảnh)'),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function runPgTransfer(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation): array
    {
        $payload = is_array($operation->operation_payload) ? $operation->operation_payload : [];
        $bankName = (string) ($payload['bank_name'] ?? '');
        $account = (string) ($payload['account_number'] ?? $payload['account'] ?? '');
        $recipientName = (string) ($payload['recipient_name'] ?? '');
        $note = (string) ($payload['content'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);

        if ($account === '' || $amount <= 0) {
            return ['ok' => false, 'message' => 'Thiếu dữ liệu chuyển tiền (STK / số tiền).'];
        }

        $cfg = (array) config('pgbank');
        $tap = (array) ($cfg['tap'] ?? []);
        $timing = (array) ($cfg['timing'] ?? []);

        $bankNameForSearch = $this->resolveBankNameForSearch($bankName, (array) ($cfg['bank_name_map'] ?? []), (array) ($cfg['bank_list'] ?? []));

        $package = 'pgbankApp.pgbank.com.vn';

        $this->log($operation, 'start', 'Bắt đầu PG chuyển tiền.', 'info', [
            'bank_input' => $bankName,
            'bank_resolved' => $bankNameForSearch,
        ]);
        $this->ensurePgLogin($device, $duoPlusApi, $operation, $package, $tap, $timing);

        // Prefill — dùng pauseLocal thay sleepCloud để tránh API overhead (~1s/lần)
        $this->tap($duoPlusApi, $device, $operation, 'tap_transfer', $tap['transfer']);
        $this->pauseLocal(0.8);
        $this->tap($duoPlusApi, $device, $operation, 'tap_other_account', $tap['other_account']);
        $this->pauseLocal(0.8);
        $this->tap($duoPlusApi, $device, $operation, 'dismiss_popup', $tap['dismiss_popup']);
        $this->pauseLocal(0.5);

        // Note — tap clear_note → focus_note → keyevent 277 → input text
        $safeNote = $this->normalizeTransferNote($note !== '' ? $note : 'ck');
        if (isset($tap['clear_note']) && is_array($tap['clear_note'])) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_clear_note', $tap['clear_note']);
            $this->pauseLocal(0.2);
        }
        $this->tap($duoPlusApi, $device, $operation, 'focus_note', $tap['note']);
        $this->pauseLocal(0.25);
        $this->sendAdb($duoPlusApi, $device, $operation, 'select_note', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_note', $this->inputTextCommand($safeNote));
        $this->pauseLocal(0.15);
        $this->tap($duoPlusApi, $device, $operation, 'blur', $tap['blur']);
        $this->pauseLocal(0.15);

        // Amount
        $this->tap($duoPlusApi, $device, $operation, 'focus_amount', $tap['amount']);
        $this->pauseLocal(0.2);
        $this->sendAdb($duoPlusApi, $device, $operation, 'clear_amount', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_amount', $this->inputTextCommand((string) $amount));
        $this->pauseLocal(0.15);
        $this->tap($duoPlusApi, $device, $operation, 'blur2', $tap['blur']);
        $this->pauseLocal(0.15);

        // Account + bank
        $this->tap($duoPlusApi, $device, $operation, 'focus_account', $tap['account']);
        $this->pauseLocal(0.2);
        $this->sendAdb($duoPlusApi, $device, $operation, 'clear_account', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_account', $this->inputTextCommand($account));
        $this->pauseLocal(0.25);

        $this->tap($duoPlusApi, $device, $operation, 'tap_bank', $tap['bank']);
        $this->pauseLocal(0.6);
        $this->tap($duoPlusApi, $device, $operation, 'tap_bank_search', $tap['bank_search']);
        $this->pauseLocal(0.35);
        $bankQuery = $bankNameForSearch !== '' ? $bankNameForSearch : $bankName;
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_bank_name', $this->inputTextCommand($bankQuery));
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_bank_typing', 1.2);
        $this->tap($duoPlusApi, $device, $operation, 'tap_bank_first_row', $tap['bank_first_row']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_after_select_bank', 1.5);

        // UI check after bank selection — botBank: kiểm tra "chuyển sang hình thức chuyển thường"
        $dumpAfterBank = $this->dumpUiText($duoPlusApi, $device, $operation);
        $lowAfterBank = mb_strtolower($dumpAfterBank);
        if (str_contains($lowAfterBank, 'chuyển sang hình thức chuyển thường')) {
            $this->log($operation, 'normal_transfer', 'App PG yêu cầu chuyển thường, tap đồng ý.');
            $normalConfirm = $tap['normal_confirm'] ?? [786, 1157];
            if (is_array($normalConfirm)) {
                $this->tap($duoPlusApi, $device, $operation, 'tap_normal_confirm', $normalConfirm);
            }
            $this->pauseLocal(0.5);
        }

        // Phase 2 — botBank: tap amount → keyevent 277 → input amount → blur → continue
        $threshold = (int) ($cfg['face_scan_threshold'] ?? 10_000_000);
        $requireFaceScan = $amount >= $threshold;

        if ($requireFaceScan) {
            if ((string) $device->pg_video_id === '') {
                $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

                return ['ok' => false, 'message' => 'Thiếu `pg_video_id` để bật livestream (>= 10tr).'];
            }
            $this->log($operation, 'livestream', 'Bật livestream để quét mặt (>=10tr).');
            $live = $duoPlusApi->startLivestream($device->duo_api_key, $device->image_id, (string) $device->pg_video_id);
            if (! $live['ok']) {
                $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

                return ['ok' => false, 'message' => 'Không bật được livestream: '.$live['message']];
            }
        }

        // Amount input (phase 2 of botBank)
        $this->tap($duoPlusApi, $device, $operation, 'focus_amount_p2', $tap['amount']);
        $this->pauseLocal(0.2);
        $this->sendAdb($duoPlusApi, $device, $operation, 'clear_amount_p2', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_amount_p2', $this->inputTextCommand((string) $amount));
        $this->pauseLocal(0.15);
        $this->tap($duoPlusApi, $device, $operation, 'blur_amount_p2', $tap['blur']);
        $this->pauseLocal(0.2);

        // Continue
        $this->tap($duoPlusApi, $device, $operation, 'tap_continue', $tap['continue']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_continue', 2.0);

        // botBank: check "chọn tài khoản/thẻ" popup → tap source_account_first
        $dumpMid = $this->dumpUiText($duoPlusApi, $device, $operation);
        if (str_contains(mb_strtolower($dumpMid), 'chọn tài khoản/thẻ')) {
            $this->log($operation, 'source_account_popup', 'Popup chọn TK nguồn, tap first.');
            $this->tap($duoPlusApi, $device, $operation, 'tap_source_account_first', $tap['source_account_first'] ?? [540, 1730]);
            $this->pauseLocal(0.5);
        }

        // Second continue
        $this->tap($duoPlusApi, $device, $operation, 'tap_continue_2', $tap['continue']);
        $waitContinue2 = $requireFaceScan ? 3.0 : 2.0;
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_continue_2', $waitContinue2);

        // botBank: check again "chọn tài khoản/thẻ" (retry)
        $dumpMid2 = $this->dumpUiText($duoPlusApi, $device, $operation);
        $lowMid2 = mb_strtolower($dumpMid2);
        if (str_contains($lowMid2, 'chọn tài khoản/thẻ')) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_source_account_retry', $tap['source_account_first'] ?? [540, 1730]);
            $this->pauseLocal(0.5);
            $this->tap($duoPlusApi, $device, $operation, 'tap_continue_3', $tap['continue']);
            $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_continue_3', 1.5);
            $dumpMid2 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $lowMid2 = mb_strtolower($dumpMid2);
        }

        // botBank: bank retry — nếu app rớt về form chuyển tiền, re-pick bank
        if (str_contains($dumpMid2, 'id/submit') && str_contains($lowMid2, 'ngân hàng')) {
            $this->log($operation, 'bank_retry', 'App rớt về form, re-pick bank.');
            $this->tap($duoPlusApi, $device, $operation, 'retry_bank_select', $tap['bank']);
            $this->pauseLocal(0.6);
            $this->tap($duoPlusApi, $device, $operation, 'retry_bank_search', $tap['bank_search']);
            $this->pauseLocal(0.35);
            $this->sendAdb($duoPlusApi, $device, $operation, 'retry_input_bank', $this->inputTextCommand($bankQuery));
            $this->sleepCloud($duoPlusApi, $device, $operation, 'retry_wait_bank_typing', 1.2);
            $this->tap($duoPlusApi, $device, $operation, 'retry_bank_first', $tap['bank_first_row']);
            $this->pauseLocal(0.5);
            $this->tap($duoPlusApi, $device, $operation, 'retry_continue', $tap['continue']);
            $this->sleepCloud($duoPlusApi, $device, $operation, 'retry_wait_continue', 2.0);
        }

        // Face scan
        if ($requireFaceScan) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_face_portrait', $tap['face_portrait']);
            $this->pauseLocal((float) ($timing['wait_face_scan'] ?? 12.0));
        }

        // botBank: pre-PIN UI check
        $dumpBeforePin = $this->dumpUiText($duoPlusApi, $device, $operation);
        $lowBeforePin = mb_strtolower($dumpBeforePin);
        if (str_contains($lowBeforePin, 'chọn tài khoản/thẻ') ||
            (str_contains($dumpBeforePin, 'id/submit') && str_contains($lowBeforePin, 'thông tin chuyển tiền'))
        ) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

            return ['ok' => false, 'message' => 'PG vẫn ở form chuyển tiền, chưa vào màn PIN. Đã hủy.'];
        }

        if ($requireFaceScan) {
            $this->pauseLocal(2.0);
        }

        // PIN entry — botBank: tap focus → (face_scan: DEL×4) → retap + keyevent per digit
        $pin = preg_replace('/\D+/', '', (string) $device->pg_pin) ?? '';
        if (strlen($pin) !== 6) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

            return ['ok' => false, 'message' => 'PIN PG không hợp lệ (cần 6 số).'];
        }

        $this->tap($duoPlusApi, $device, $operation, 'otp_focus', $tap['otp_focus']);
        $this->pauseLocal(0.25);

        if ($requireFaceScan) {
            for ($del = 0; $del < 4; $del++) {
                $this->sendAdb($duoPlusApi, $device, $operation, "otp_del_{$del}", 'input keyevent 67');
                $this->pauseLocal(0.04);
            }
            $this->pauseLocal(0.1);
        }

        $keycodeBase = $requireFaceScan ? 144 : 7;
        for ($i = 0; $i < 6; $i++) {
            $d = (int) $pin[$i];
            $this->tap($duoPlusApi, $device, $operation, "otp_refocus_{$i}", $tap['otp_focus']);
            $this->pauseLocal(0.06);
            $this->sendAdb($duoPlusApi, $device, $operation, "otp_digit_{$d}_{$i}", 'input keyevent '.($keycodeBase + $d));
            $this->pauseLocal(0.1);
        }
        $this->pauseLocal(2.0);

        // Success / error detection
        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
        $lowDump = mb_strtolower($dump);

        // botBank: OTP lock detection
        if (str_contains($lowDump, 'khoá tính năng') && str_contains($lowDump, 'smart otp')) {
            $lockDismiss = $tap['smart_otp_lock_dismiss'] ?? [294, 1186];
            if (is_array($lockDismiss)) {
                $this->tap($duoPlusApi, $device, $operation, 'tap_otp_lock_dismiss', $lockDismiss);
            }
            $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

            return ['ok' => false, 'message' => 'App khoá Smart OTP (sai mật khẩu nhiều lần). Cần kích hoạt lại.'];
        }

        // botBank: wrong PIN detection
        if (str_contains($lowDump, 'mật khẩu không chính xác')) {
            $pinDismiss = $tap['pin_wrong_dismiss'] ?? [540, 1158];
            if (is_array($pinDismiss)) {
                $this->tap($duoPlusApi, $device, $operation, 'tap_pin_wrong_dismiss', $pinDismiss);
            }
            $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

            return ['ok' => false, 'message' => 'PIN PG không chính xác. Kiểm tra lại pg_pin trong database.'];
        }

        $ok = $this->containsAny($dump, ['thành công', 'thanh cong', 'giao dịch thành công', 'chuyển tiền thành công']);
        if (! $ok) {
            $this->pauseLocal(3.0);
            $dump2 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->containsAny($dump2, ['thành công', 'thanh cong', 'giao dịch thành công', 'chuyển tiền thành công']);
        }
        if (! $ok) {
            $this->pauseLocal(2.0);
            $dump3 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->containsAny($dump3, ['thành công', 'thanh cong', 'giao dịch thành công', 'chuyển tiền thành công']);
        }

        $this->sendAdb($duoPlusApi, $device, $operation, 'close_app', "am force-stop {$package}");

        if (! $ok) {
            return ['ok' => false, 'message' => 'PG chuyển tiền thất bại (không thấy marker thành công).'];
        }

        if ($device->pg_balance !== null) {
            $device->forceFill([
                'pg_balance' => max(0, (float) $device->pg_balance - $amount),
                'pg_balance_updated_at' => now(),
            ])->save();
        }

        return [
            'ok' => true,
            'message' => $this->appendReceiptUrl(
                operation: $operation,
                channel: 'pg',
                baseMessage: 'PG chuyển tiền thành công'.($recipientName !== '' ? " ({$recipientName})" : '').'.',
                duoPlusApi: $duoPlusApi,
                device: $device,
            ),
        ];
    }

    private function appendReceiptUrl(
        DeviceOperation $operation,
        string $channel,
        string $baseMessage,
        DuoPlusApi $duoPlusApi,
        Device $device,
    ): string {
        $url = $this->captureAndStoreReceiptScreenshot($operation, $channel, $duoPlusApi, $device);
        if ($url === null) {
            return $baseMessage;
        }

        return $baseMessage.' Ảnh: '.$url;
    }

    private function captureAndStoreReceiptScreenshot(
        DeviceOperation $operation,
        string $channel,
        DuoPlusApi $duoPlusApi,
        Device $device,
    ): ?string {
        try {
            $result = $duoPlusApi->command(
                $device->duo_api_key,
                $device->image_id,
                'screencap -p /sdcard/receipt.png && base64 /sdcard/receipt.png',
            );
            if (! $result['ok']) {
                $this->log($operation, 'receipt_capture', 'Không chụp được biên lai: '.$result['message'], 'warning');
                return null;
            }

            $b64 = (string) data_get($result['data'], 'data.content', data_get($result['data'], 'data.data.content', ''));
            $b64 = preg_replace('/\\s+/', '', $b64) ?? '';
            if ($b64 === '') {
                $this->log($operation, 'receipt_capture', 'Không có content base64 từ DuoPlus.', 'warning');
                return null;
            }

            $binary = base64_decode($b64, true);
            if ($binary === false) {
                $this->log($operation, 'receipt_capture', 'Base64 decode thất bại.', 'warning');
                return null;
            }

            $img = @imagecreatefromstring($binary);
            if (! $img) {
                $this->log($operation, 'receipt_capture', 'Không đọc được ảnh từ binary.', 'warning');
                return null;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $maxW = 720;
            if ($w > $maxW) {
                $newH = (int) round($h * ($maxW / $w));
                $scaled = imagescale($img, $maxW, $newH, IMG_BICUBIC_FIXED);
                if ($scaled) {
                    imagedestroy($img);
                    $img = $scaled;
                }
            }

            $dir = "transfer-receipts/{$channel}/".now()->format('Y/m/d');
            $filename = 'op-'.$operation->id.'-'.now()->format('His').'.jpg';
            $path = $dir.'/'.$filename;

            ob_start();
            imagejpeg($img, null, 50);
            $jpeg = (string) ob_get_clean();
            imagedestroy($img);

            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk('public');
            $disk->put($path, $jpeg);

            return url($disk->url($path));
        } catch (Throwable $e) {
            $this->log($operation, 'receipt_capture', 'Lỗi chụp biên lai: '.$e->getMessage(), 'warning');
            return null;
        }
    }

    /**
     * Chụp ảnh debug (dùng khi calibrate toạ độ).
     */
    private function captureAndStoreDebugScreenshot(
        DeviceOperation $operation,
        string $tag,
        DuoPlusApi $duoPlusApi,
        Device $device,
    ): ?string {
        $channel = 'debug';
        try {
            $result = $duoPlusApi->command(
                $device->duo_api_key,
                $device->image_id,
                'screencap -p /sdcard/debug.png && base64 /sdcard/debug.png',
            );
            if (! $result['ok']) {
                $this->log($operation, 'debug_capture', 'Không chụp được ảnh debug: '.$result['message'], 'warning');
                return null;
            }

            $b64 = (string) data_get($result['data'], 'data.content', data_get($result['data'], 'data.data.content', ''));
            $b64 = preg_replace('/\\s+/', '', $b64) ?? '';
            if ($b64 === '') {
                return null;
            }
            $binary = base64_decode($b64, true);
            if ($binary === false) {
                return null;
            }

            $img = @imagecreatefromstring($binary);
            if (! $img) {
                return null;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $maxW = 720;
            if ($w > $maxW) {
                $newH = (int) round($h * ($maxW / $w));
                $scaled = imagescale($img, $maxW, $newH, IMG_BICUBIC_FIXED);
                if ($scaled) {
                    imagedestroy($img);
                    $img = $scaled;
                }
            }

            $dir = "transfer-receipts/{$channel}/".now()->format('Y/m/d');
            $filename = $tag.'-op-'.$operation->id.'-'.now()->format('His').'.jpg';
            $path = $dir.'/'.$filename;

            ob_start();
            imagejpeg($img, null, 55);
            $jpeg = (string) ob_get_clean();
            imagedestroy($img);

            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk('public');
            $disk->put($path, $jpeg);
            return url($disk->url($path));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $tap
     * @param  array<string, mixed>  $timing
     */
    private function ensurePgLogin(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation, string $package, array $tap, array $timing): void
    {
        $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop', "am force-stop {$package}");
        $this->pauseLocal(0.3);
        $this->sendAdb($duoPlusApi, $device, $operation, 'open_app', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_open', (float) ($timing['wait_open'] ?? 3.0));
        $this->tap($duoPlusApi, $device, $operation, 'focus_password', $tap['password']);
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_password', $this->inputTextCommand($device->pg_pass));
        $this->tap($duoPlusApi, $device, $operation, 'tap_login', $tap['login']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_after_login', (float) ($timing['wait_after_login'] ?? 4.0));

        $skip = $tap['skip'] ?? [248, 1856];
        if (is_array($skip)) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_skip', $skip);
        }
        $this->pauseLocal(0.5);
    }

    /**
     * @param  array<string, mixed>  $tap
     * @param  array<string, mixed>  $timing
     */
    private function ensureBacaLogin(Device $device, DuoPlusApi $duoPlusApi, DeviceOperation $operation, string $package, array $tap, array $timing): void
    {
        $this->sendAdb($duoPlusApi, $device, $operation, 'force_stop', "am force-stop {$package}");
        $this->sendAdb($duoPlusApi, $device, $operation, 'sleep_short', 'sleep 0.35');
        $this->sendAdb($duoPlusApi, $device, $operation, 'open_app', "monkey -p {$package} -c android.intent.category.LAUNCHER 1");
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_open', (float) ($timing['wait_open'] ?? 3.0));
        $this->tap($duoPlusApi, $device, $operation, 'focus_password', $tap['password']);
        $this->sendAdb($duoPlusApi, $device, $operation, 'input_password', $this->inputTextCommand($device->baca_pass));
        $this->tap($duoPlusApi, $device, $operation, 'tap_login', $tap['login']);
        $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_after_login', (float) ($timing['wait_after_login'] ?? 3.0));
    }

    /**
     * @param  array<int, int>  $xy
     */
    private function tap(DuoPlusApi $duoPlusApi, Device $device, DeviceOperation $operation, string $stage, array $xy): void
    {
        $this->sendAdb($duoPlusApi, $device, $operation, $stage, 'input tap '.$xy[0].' '.$xy[1]);
    }

    private function sleepCloud(DuoPlusApi $duoPlusApi, Device $device, DeviceOperation $operation, string $stage, float $seconds): void
    {
        $this->sendAdb($duoPlusApi, $device, $operation, $stage, 'sleep '.number_format($seconds, 2, '.', ''));
    }

    private function pauseLocal(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    private function parseBalanceFromDump(string $dump): ?int
    {
        $text = mb_strtolower($dump);

        $text = str_replace('*', '', $text);

        // 1) Ưu tiên bắt số đứng gần từ khoá "số dư" / "so du".
        if (preg_match('/(số\\s*dư|so\\s*du)[^0-9]{0,80}([0-9][0-9\\.,\\s]{0,30})/u', $text, $m)) {
            $raw = $m[2] ?? '';
            $num = preg_replace('/[^0-9]/', '', $raw) ?? '';
            if ($num !== '' && ctype_digit($num)) {
                return (int) $num;
            }
        }

        // 2) Bắt tất cả amount có currency marker (VND/vnđ/₫/đ).
        $candidates = [];
        if (preg_match_all('/([0-9][0-9\\.,\\s]{3,30})\\s*(vnd|vnđ|đ|₫)/u', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $raw = $m[1] ?? '';
                $num = preg_replace('/[^0-9]/', '', $raw) ?? '';
                if ($num !== '' && ctype_digit($num)) {
                    $candidates[] = (int) $num;
                }
            }
        }

        if (! empty($candidates)) {
            return max($candidates);
        }

        // 3) Nếu không có marker tiền rõ, thử tìm quanh “tài khoản thanh toán”.
        $anchor = 'tài khoản thanh toán';
        $pos = mb_strpos($text, $anchor);
        if ($pos !== false) {
            $window = mb_substr($text, (int) $pos, 900);
            if (preg_match_all('/([0-9][0-9\\.,\\s]{3,30})\\s*(vnd|vnđ|đ|₫)/u', $window, $mm2, PREG_SET_ORDER)) {
                $best = null;
                foreach ($mm2 as $m) {
                    $raw = $m[1] ?? '';
                    $num = preg_replace('/[^0-9]/', '', $raw) ?? '';
                    if ($num === '' || ! ctype_digit($num)) continue;
                    $val = (int) $num;
                    $best = $best === null ? $val : max($best, $val);
                }
                if ($best !== null) {
                    return $best;
                }
            }
        }

        // Fallback: tìm số lớn nhất có vẻ giống tiền.
        if (preg_match_all('/[0-9][0-9\\.,\\s]{4,20}/u', $text, $matches) && ! empty($matches[0])) {
            $best = null;
            foreach ($matches[0] as $candidate) {
                $num = preg_replace('/[^0-9]/', '', $candidate) ?? '';
                if ($num === '' || ! ctype_digit($num)) {
                    continue;
                }
                $value = (int) $num;
                $best = $best === null ? $value : max($best, $value);
            }

            return $best;
        }

        return null;
    }

    private function sendAdb(
        DuoPlusApi $duoPlusApi,
        Device $device,
        DeviceOperation $operation,
        string $stage,
        string $command,
    ): void {
        $attempts = 0;
        $result = ['ok' => false, 'message' => '', 'data' => []];
        do {
            $attempts++;
            $result = $duoPlusApi->command($device->duo_api_key, $device->image_id, $command);
            if ($result['ok']) {
                break;
            }
            if (! $this->isTransientCommandTimeout((string) $result['message'])) {
                break;
            }
            usleep(400_000);
        } while ($attempts < 3);

        if (! $result['ok']) {
            $message = $result['message'] !== '' ? $result['message'] : 'DuoPlus command lỗi.';
            $this->log($operation, $stage, $message, 'error', ['command' => $command]);

            throw new \RuntimeException($message);
        }

        $this->log($operation, $stage, 'OK', 'info', ['command' => $command]);
    }

    private function dumpUiText(DuoPlusApi $duoPlusApi, Device $device, DeviceOperation $operation): string
    {
        $attempts = 0;
        $result = ['ok' => false, 'message' => '', 'data' => []];
        $command = 'uiautomator dump /sdcard/uidump.xml && cat /sdcard/uidump.xml';
        do {
            $attempts++;
            $result = $duoPlusApi->command($device->duo_api_key, $device->image_id, $command);
            if ($result['ok']) {
                break;
            }
            if (! $this->isTransientCommandTimeout((string) $result['message'])) {
                break;
            }
            usleep(400_000);
        } while ($attempts < 3);

        if (! $result['ok']) {
            $this->log($operation, 'dump_ui', $result['message'] !== '' ? $result['message'] : 'Dump UI lỗi.', 'error');

            return '';
        }

        $content = (string) data_get($result['data'], 'data.content', '');
        if ($content === '') {
            $content = (string) data_get($result['data'], 'data.data.content', '');
        }
        if ($content === '') {
            $content = (string) data_get($result['data'], 'data.data.data.content', '');
        }

        $this->log($operation, 'dump_ui', 'Đã dump UI thành công.', 'info', [
            'preview' => str($content)->limit(300)->value(),
            'length' => mb_strlen($content),
        ]);

        if ($content === '') {
            $this->log($operation, 'dump_ui_empty', 'Dump UI trả về rỗng (không có content).', 'warning', [
                'keys' => array_keys((array) ($result['data'] ?? [])),
            ]);
        }

        return mb_strtolower($content);
    }

    private function isTransientCommandTimeout(string $message): bool
    {
        $m = mb_strtolower($message);

        return str_contains($m, 'command operation timed out') || str_contains($m, 'timed out');
    }

    /**
     * Tìm tên ngân hàng đúng trong app (PG/Bắc Á) dựa trên tên từ banklookup.
     * Trả về phần tên chính (trước ngoặc) để gõ vào ô search trên app.
     *
     * VD: input "BacABank" + PG list → "BAC A"
     *     input "VPBank"   + PG list → "VIET NAM THINH VUONG"
     *     input "PGBank"   + Bắc Á list → "PGBANK"
     *
     * @param  array<string, string>  $map      bank_name_map config (override cứng)
     * @param  list<string>           $bankList bank_list config
     */
    private function resolveBankNameForSearch(string $bankName, array $map, array $bankList = []): string
    {
        if ($bankName === '') {
            return '';
        }

        $strip = static fn (string $s): string => preg_replace('/[^A-Z0-9]/', '', strtoupper($s)) ?? strtoupper($s);

        $key = $strip($bankName);

        // 1) Exact map override
        if (isset($map[$key]) && is_string($map[$key]) && $map[$key] !== '') {
            return $map[$key];
        }

        if (empty($bankList)) {
            return $bankName;
        }

        // Pre-process bank list: mỗi entry tách thành main part + codes trong ngoặc
        $candidates = [];
        foreach ($bankList as $entry) {
            $mainPart = trim(preg_replace('/\s*\(.*$/', '', $entry) ?? $entry);
            $codes = [];
            if (preg_match_all('/\(([^)]+)\)/', $entry, $m)) {
                foreach ($m[1] as $raw) {
                    // Split on "/" for multi-code like "TECHCOMBANK/TCB"
                    foreach (explode('/', $raw) as $piece) {
                        $codes[] = trim($piece);
                    }
                }
            }
            $candidates[] = [
                'entry' => $entry,
                'main' => $mainPart,
                'main_stripped' => $strip($mainPart),
                'full_stripped' => $strip($entry),
                'codes' => array_map($strip, $codes),
            ];
        }

        // Helper: lấy phần tên chính (trước ngoặc) để gõ vào ô search app
        $mainOf = static fn (string $entry): string => trim(preg_replace('/\s*\(.*$/', '', $entry) ?? $entry);

        // 2) Exact full match (stripped)
        foreach ($candidates as $c) {
            if ($c['full_stripped'] === $key) {
                return $mainOf($c['entry']);
            }
        }

        // 3) Exact code match — "VPBANK" matches (VPBANK)
        foreach ($candidates as $c) {
            foreach ($c['codes'] as $code) {
                if ($code === $key) {
                    return $mainOf($c['entry']);
                }
            }
        }

        // 4) Fuzzy match: input starts with main, main starts with input, code overlap
        $bestEntry = null;
        $bestScore = 0;

        foreach ($candidates as $c) {
            $mainS = $c['main_stripped'];

            if ($mainS !== '' && str_starts_with($key, $mainS)) {
                $score = 200 + strlen($mainS);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestEntry = $c['entry'];
                }
            }

            if ($mainS !== '' && str_starts_with($mainS, $key)) {
                $score = 190 + strlen($key);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestEntry = $c['entry'];
                }
            }

            foreach ($c['codes'] as $code) {
                if (str_contains($key, $code) || str_contains($code, $key)) {
                    $score = 180 + strlen($code);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestEntry = $c['entry'];
                    }
                }
            }

            if (str_contains($c['full_stripped'], $key)) {
                $score = 150 + strlen($key);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestEntry = $c['entry'];
                }
            }
            if (str_contains($key, $c['full_stripped'])) {
                $score = 140 + strlen($c['full_stripped']);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestEntry = $c['entry'];
                }
            }
        }

        return $bestEntry !== null ? $mainOf($bestEntry) : $bankName;
    }

    private function inputTextCommand(string $raw): string
    {
        $normalized = preg_replace('/\s+/', '%s', $raw) ?? '';
        $escaped = preg_replace('/([\\\\\"\'`$;&|<>])/', '\\\\$1', $normalized) ?? '';

        return 'input text '.$escaped;
    }

    /**
     * Chuẩn hóa nội dung chuyển khoản: in hoa, bỏ dấu, chỉ giữ [A-Z0-9 ] an toàn cho ADB input text.
     */
    private function normalizeTransferNote(string $raw): string
    {
        $upper = mb_strtoupper(trim($raw));

        $map = [
            'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A',
            'Ă' => 'A', 'Ắ' => 'A', 'Ằ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A',
            'Â' => 'A', 'Ấ' => 'A', 'Ầ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
            'Đ' => 'D',
            'È' => 'E', 'É' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E',
            'Ê' => 'E', 'Ế' => 'E', 'Ề' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O',
            'Ô' => 'O', 'Ố' => 'O', 'Ồ' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O',
            'Ơ' => 'O', 'Ớ' => 'O', 'Ờ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U',
            'Ư' => 'U', 'Ứ' => 'U', 'Ừ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
            'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y',
        ];

        $stripped = strtr($upper, $map);

        // Chỉ giữ A-Z, 0-9, space — loại bỏ ký tự đặc biệt gây lỗi ADB
        $clean = preg_replace('/[^A-Z0-9 ]/', '', $stripped) ?? $stripped;

        // Gộp nhiều space thành 1, trim
        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
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
        $fresh = $operation->fresh()->load(['logs', 'requester:id,name', 'device']);
        if (! $fresh) {
            return;
        }

        // Luôn broadcast payload gọn để tránh vượt giới hạn.
        try {
            DeviceOperationUpdated::dispatch($fresh->toBroadcastArray());
        } catch (Throwable $e) {
            // Realtime không được phép làm hỏng job (reverb/pusher có thể down tạm thời).
            Log::warning('Broadcast failed', [
                'operation_id' => $fresh->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
