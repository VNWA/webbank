<?php

namespace App\Jobs;

use App\Events\DeviceOperationUpdated;
use App\Models\Device;
use App\Models\DeviceOperation;
use App\Models\DeviceOperationLog;
use App\Models\TransferHistory;
use App\Services\DuoPlusApi;
use App\Support\BalanceFromUiDumpParser;
use App\Support\PgTransferSuccessfulUiDump;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDeviceOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    /**
     * Thời điểm (microtime) broadcast gần nhất từ phương thức log — tránh gửi trùng lặp tới Reverb khi có nhiều dòng log liên tiếp.
     */
    private float $lastBroadcastFromLogAt = 0.0;

    private const LOG_BROADCAST_MIN_INTERVAL_SEC = 1.5;

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
            if ($result['ok'] && in_array($operation->operation_type, ['pg_transfer', 'baca_transfer'], true)) {
                $this->recordSuccessfulTransfer($operation);
            }
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
        $this->ensureBacaLogin($device, $duoPlusApi, $operation, $package, $tapCfg, $timing);

        $this->tapBacaRevealBalanceForAttempt($duoPlusApi, $device, $operation, $tapCfg, $timing, 1);
        $dumpAfterReveal = $this->dumpUiText($duoPlusApi, $device, $operation);

        $ok = $this->containsAny($dumpAfterReveal, ['tài khoản thanh toán']);
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
        $this->ensureBacaLogin($device, $duoPlusApi, $operation, $package, $tapCfg, $timing);

        $balance = null;
        $dump = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->tapBacaRevealBalanceForAttempt($duoPlusApi, $device, $operation, $tapCfg, $timing, $attempt);
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
            $balance = BalanceFromUiDumpParser::parse($dump);
            if ($balance !== null) {
                break;
            }
            if ($attempt < 3) {
                $this->log($operation, 'reveal_balance_retry', 'Chưa đọc được số dư, thử lại tap mở che (lần '.($attempt + 1).').', 'warning');
            }
        }
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

        $balance = BalanceFromUiDumpParser::parse($dump2);
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

        $internalTransfer = ! empty($payload['internal_transfer']);

        if ($internalTransfer) {
            $icfg = (array) ($cfg['internal_transfer'] ?? []);
            $itap = (array) ($icfg['tap'] ?? []);
            $itim = (array) ($icfg['timing'] ?? []);

            $this->log($operation, 'baca_transfer_branch', 'Bắc Á: luồng chuyển cùng ngân hàng (tab + STK + kiểm tra).', 'info');

            $sameTab = $itap['same_bank_tab'] ?? [837, 311];
            if (is_array($sameTab)) {
                $this->tap($duoPlusApi, $device, $operation, 'baca_int_same_bank_tab', $sameTab);
            }
            $this->pauseLocal((float) ($itim['wait_after_same_bank_tab'] ?? 0.8));

            $accTap = isset($itap['account']) && is_array($itap['account']) ? $itap['account'] : $tap['account'];
            $this->tap($duoPlusApi, $device, $operation, 'baca_int_focus_account', $accTap);
            $this->sendAdb($duoPlusApi, $device, $operation, 'baca_int_clear_account', 'input keyevent 277');
            $this->sendAdb($duoPlusApi, $device, $operation, 'baca_int_input_account', $this->inputTextCommand($account));
            $this->sleepCloud($duoPlusApi, $device, $operation, 'baca_int_wait_account', 1.0);

            $checkTap = $itap['check_beneficiary'] ?? null;
            if (is_array($checkTap)) {
                $this->tap($duoPlusApi, $device, $operation, 'baca_int_check_beneficiary', $checkTap);
                $this->pauseLocal((float) ($itim['wait_after_check'] ?? 2.0));
            }

            $contTap = $itap['continue'] ?? $tap['continue_step_1'];
            if (! is_array($contTap)) {
                return ['ok' => false, 'message' => 'Thiếu tọa độ `internal_transfer.tap.continue` cho Bắc Á nội bộ.'];
            }
            $this->tap($duoPlusApi, $device, $operation, 'baca_int_continue', $contTap);
            $this->pauseLocal((float) ($itim['wait_after_continue'] ?? 1.0));
        } else {
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
        }

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

        // OTP / Smart PIN entry — cùng luồng tap focus + numpad cho mọi mức tiền (botBank).
        $pin = preg_replace('/\D+/', '', (string) $device->baca_pin) ?? '';
        if (strlen($pin) !== 6) {
            return ['ok' => false, 'message' => 'PIN Bắc Á không hợp lệ (cần 6 số).'];
        }

        if ($didFaceScan) {
            $this->pauseLocal((float) ($timing['wait_after_face_scan_before_otp'] ?? 1.2));
        }
        $this->pauseLocal((float) ($timing['wait_before_otp'] ?? 0.6));
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
        $ok = $this->bacaTransferDumpLooksSuccessful($dump);
        if (! $ok) {
            $this->pauseLocal((float) ($timing['wait_after_success_retry'] ?? 3.0));
            $dump2 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->bacaTransferDumpLooksSuccessful($dump2);
        }

        $receiptPng = $ok
            ? $this->captureReceiptScreenshotPng($operation, $duoPlusApi, $device)
            : null;

        if (! $ok) {
            return [
                'ok' => false,
                'message' => 'Bắc Á chuyển tiền thất bại (không thấy marker thành công).',
            ];
        }

        // Chuyển tiền: không đóng app (thành công hay thất bại) — lần chạy sau ensure*Login vẫn force-stop trước khi mở app.

        // Update cached balance (best-effort)
        if ($device->baca_balance !== null) {
            $device->forceFill([
                'baca_balance' => max(0, (float) $device->baca_balance - $amount),
                'baca_balance_updated_at' => now(),
            ])->save();
        }

        return [
            'ok' => true,
            'message' => $this->formatTransferSuccessMessage(
                'Bắc Á chuyển tiền thành công'.($recipientName !== '' ? " ({$recipientName})" : '').'.',
                $this->sendReceiptToTelegram(
                    $operation,
                    'baca',
                    $receiptPng,
                    $this->buildTelegramReceiptCaption(
                        $device,
                        'baca',
                        'Bắc Á Bank',
                        $account,
                        $bankName !== '' ? $bankName : $bankNameForSearch,
                        $amount,
                        $recipientName,
                        $safeNote,
                    ),
                ),
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
        $icfg = (array) ($cfg['internal_transfer'] ?? []);
        $itim = (array) ($icfg['timing'] ?? []);

        $bankNameForSearch = $this->resolveBankNameForSearch($bankName, (array) ($cfg['bank_name_map'] ?? []), (array) ($cfg['bank_list'] ?? []));

        $package = 'pgbankApp.pgbank.com.vn';

        $this->log($operation, 'start', 'Bắt đầu PG chuyển tiền.', 'info', [
            'bank_input' => $bankName,
            'bank_resolved' => $bankNameForSearch,
        ]);
        $this->ensurePgLogin($device, $duoPlusApi, $operation, $package, $tap, $timing);

        // Home: tap Chuyển tiền — nội bộ: bước tiếp theo là "Trong hệ thống" (chuyenkhoannoibo.txt), không qua TK khác.
        $this->tap($duoPlusApi, $device, $operation, 'tap_transfer', $tap['transfer']);
        $this->pauseLocal(0.8);

        $safeNote = $this->normalizeTransferNote($note !== '' ? $note : 'ck');
        $internalTransfer = ! empty($payload['internal_transfer']);
        $this->log(
            $operation,
            'pg_transfer_branch',
            $internalTransfer ? 'PG: luồng chuyển khoản nội bộ (trong hệ thống).' : 'PG: luồng chuyển liên ngân hàng.',
            'info',
        );

        if (! $internalTransfer) {
            $this->tap($duoPlusApi, $device, $operation, 'tap_other_account', $tap['other_account']);
            $this->pauseLocal(0.8);
            $this->tap($duoPlusApi, $device, $operation, 'dismiss_popup', $tap['dismiss_popup']);
            $this->pauseLocal(0.5);
        }

        if ($internalTransfer) {
            $internalErr = $this->runPgInternalTransferScreens(
                $device,
                $duoPlusApi,
                $operation,
                $tap,
                $account,
                $amount,
                $safeNote,
            );
            if ($internalErr !== null) {
                return $internalErr;
            }
        } else {
            // Nội dung liên NH: tap xóa (mặc định không bỏ qua) → tap ô → `input text` → tap blur.
            $this->pauseLocal((float) ($timing['note_wait_before_note_block'] ?? 0.4));
            $clearNoteTap = isset($tap['clear_note']) && is_array($tap['clear_note']) && count($tap['clear_note']) >= 2
                ? [(float) $tap['clear_note'][0], (float) $tap['clear_note'][1]]
                : [948.2, 1540.8];
            $focusNoteTap = isset($tap['note']) && is_array($tap['note']) && count($tap['note']) >= 2
                ? [(float) $tap['note'][0], (float) $tap['note'][1]]
                : [940.0, 1540.0];
            $noteBlurTap = isset($tap['note_blur']) && is_array($tap['note_blur']) && count($tap['note_blur']) >= 2
                ? [(float) $tap['note_blur'][0], (float) $tap['note_blur'][1]]
                : (isset($tap['blur']) && is_array($tap['blur']) && count($tap['blur']) >= 2
                    ? [(float) $tap['blur'][0], (float) $tap['blur'][1]]
                    : [20.0, 604.0]);
            $this->log($operation, 'pg_ln_note_clear_xy', 'PG liên NH: chuẩn bị tap nút xóa nội dung CK.', 'info', [
                'clear_xy_raw' => $clearNoteTap,
                'clear_xy_int' => $this->normalizeTapPair($clearNoteTap),
            ]);
            $this->fillPgTransferNoteTwoTapFlow(
                $duoPlusApi,
                $device,
                $operation,
                $timing,
                $clearNoteTap,
                $focusNoteTap,
                $noteBlurTap,
                'pg_ln',
                $safeNote,
            );
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
        }

        $bankQuery = $bankNameForSearch !== '' ? $bankNameForSearch : $bankName;

        // Phase 2 — botBank: tap amount → keyevent 277 → input amount → blur → continue
        $threshold = (int) ($cfg['face_scan_threshold'] ?? 10_000_000);
        $requireFaceScan = $amount >= $threshold;

        if ($requireFaceScan) {
            if ((string) $device->pg_video_id === '') {
                return ['ok' => false, 'message' => 'Thiếu `pg_video_id` để bật livestream (>= 10tr).'];
            }
            $this->log($operation, 'livestream', 'Bật livestream để quét mặt (>=10tr).');
            $live = $duoPlusApi->startLivestream($device->duo_api_key, $device->image_id, (string) $device->pg_video_id);
            if (! $live['ok']) {
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

        // botBank: bank retry — nếu app rớt về form chuyển tiền, re-pick bank (chỉ luồng liên NH)
        if (! $internalTransfer && str_contains($dumpMid2, 'id/submit') && str_contains($lowMid2, 'ngân hàng')) {
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
            return ['ok' => false, 'message' => 'PG vẫn ở form chuyển tiền, chưa vào màn PIN.'];
        }

        if ($requireFaceScan) {
            $this->pauseLocal(2.0);
        }

        // PIN/OTP (PG): một luồng cho CK liên NH và CK nội bộ — luôn lấy 6 số từ `pg_pin` trên Device (giống CK thường).
        $pin = preg_replace('/\D+/', '', (string) $device->pg_pin) ?? '';
        if (strlen($pin) !== 6) {
            return ['ok' => false, 'message' => 'PIN PG không hợp lệ (cần 6 số trong pg_pin).'];
        }

        $otpFocus = is_array($tap['otp_focus'] ?? null) && count($tap['otp_focus']) >= 2
            ? [(int) $tap['otp_focus'][0], (int) $tap['otp_focus'][1]]
            : [189, 529];

        $this->submitPgTransferOtpDigits(
            $device,
            $duoPlusApi,
            $operation,
            $timing,
            $requireFaceScan,
            $otpFocus,
        );

        $waitAfterOtp = (float) ($timing['wait_after_otp'] ?? 2.5);
        if ($internalTransfer) {
            $waitAfterOtp += (float) ($itim['wait_after_otp_extra'] ?? 0.0);
        }
        $this->pauseLocal($waitAfterOtp);

        // Success / error detection
        $dump2 = null;
        $dump3 = null;
        $dump4 = null;
        $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
        $lowDump = mb_strtolower($dump);

        // Sau PIN, app PG đôi khi hiện popup "chuyển sang hình thức chuyển thường" (kể cả luồng nội bộ).
        $normalTransferNeedle = 'chuyển sang hình thức chuyển thường';
        for ($normalRound = 0; $normalRound < 2; $normalRound++) {
            if (! str_contains($lowDump, $normalTransferNeedle)) {
                break;
            }
            $this->log($operation, 'normal_transfer', 'App PG yêu cầu chuyển thường (sau PIN), tap Đồng ý.', 'info');
            $normalConfirm = $tap['normal_confirm'] ?? [786, 1157];
            if (is_array($normalConfirm)) {
                $this->tap($duoPlusApi, $device, $operation, 'tap_normal_confirm_post_pin_'.$normalRound, $normalConfirm);
            }
            $this->pauseLocal(1.2);
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
            $lowDump = mb_strtolower($dump);
        }

        // botBank: OTP lock detection
        if (str_contains($lowDump, 'khoá tính năng') && str_contains($lowDump, 'smart otp')) {
            $lockDismiss = $tap['smart_otp_lock_dismiss'] ?? [294, 1186];
            if (is_array($lockDismiss)) {
                $this->tap($duoPlusApi, $device, $operation, 'tap_otp_lock_dismiss', $lockDismiss);
            }

            return ['ok' => false, 'message' => 'App khoá Smart OTP (sai mật khẩu nhiều lần). Cần kích hoạt lại.'];
        }

        // botBank: wrong PIN detection
        if (str_contains($lowDump, 'mật khẩu không chính xác')) {
            $pinDismiss = $tap['pin_wrong_dismiss'] ?? [540, 1158];
            if (is_array($pinDismiss)) {
                $this->tap($duoPlusApi, $device, $operation, 'tap_pin_wrong_dismiss', $pinDismiss);
            }

            return ['ok' => false, 'message' => 'PIN PG không chính xác. Kiểm tra lại pg_pin trong database.'];
        }

        $ok = $this->pgTransferDumpLooksSuccessful($dump);
        if (! $ok) {
            $this->pauseLocal(3.0);
            $dump2 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->pgTransferDumpLooksSuccessful($dump2);
        }
        if (! $ok) {
            $this->pauseLocal(2.0);
            $dump3 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->pgTransferDumpLooksSuccessful($dump3);
        }
        if (! $ok) {
            $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_success_after_otp', 5.0);
            $dump4 = $this->dumpUiText($duoPlusApi, $device, $operation);
            $ok = $this->pgTransferDumpLooksSuccessful($dump4);
        }
        $lastDumpForLog = $dump4 ?? $dump3 ?? $dump2 ?? $dump ?? '';

        $receiptPng = $ok
            ? $this->captureReceiptScreenshotPng($operation, $duoPlusApi, $device)
            : null;

        if (! $ok) {
            $tail = mb_substr($lastDumpForLog, -1400);
            $this->log($operation, 'pg_transfer_no_success_marker', 'Sau OTP không thấy chữ thành công trong dump.', 'warning', [
                'dump_tail' => str($tail)->limit(900)->value(),
            ]);

            return ['ok' => false, 'message' => 'PG chuyển tiền thất bại (không thấy marker thành công).'];
        }

        // Chuyển tiền: không đóng app — lần chạy sau ensurePgLogin vẫn force-stop trước khi mở app.

        if ($device->pg_balance !== null) {
            $device->forceFill([
                'pg_balance' => max(0, (float) $device->pg_balance - $amount),
                'pg_balance_updated_at' => now(),
            ])->save();
        }

        return [
            'ok' => true,
            'message' => $this->formatTransferSuccessMessage(
                'PG chuyển tiền thành công'.($recipientName !== '' ? " ({$recipientName})" : '').'.',
                $this->sendReceiptToTelegram(
                    $operation,
                    'pg',
                    $receiptPng,
                    $this->buildTelegramReceiptCaption(
                        $device,
                        'pg',
                        'PG Bank',
                        $account,
                        $bankName !== '' ? $bankName : $bankQuery,
                        $amount,
                        $recipientName,
                        $safeNote,
                    ),
                ),
            ),
        ];
    }

    private function formatTransferSuccessMessage(string $baseMessage, bool $telegramSent): string
    {
        if ($telegramSent) {
            return $baseMessage.' Đã gửi ảnh biên lai về Telegram.';
        }

        return $baseMessage.' Chưa gửi được ảnh biên lai Telegram (xem log receipt_capture).';
    }

    private function recordSuccessfulTransfer(DeviceOperation $operation): void
    {
        try {
            $payload = is_array($operation->operation_payload) ? $operation->operation_payload : [];
            $bankName = (string) ($payload['bank_name'] ?? '');
            $account = (string) ($payload['account_number'] ?? $payload['account'] ?? '');
            $recipientName = (string) ($payload['recipient_name'] ?? '');
            $note = (string) ($payload['content'] ?? '');
            $amount = (int) ($payload['amount'] ?? 0);
            $channel = $operation->operation_type === 'pg_transfer' ? 'pg' : 'baca';

            if ($account === '' || $amount <= 0) {
                return;
            }

            TransferHistory::query()->firstOrCreate(
                ['device_operation_id' => $operation->id],
                [
                    'device_id' => $operation->device_id,
                    'channel' => $channel,
                    'bank_name' => $bankName !== '' ? $bankName : null,
                    'account_number' => $account,
                    'recipient_name' => $recipientName !== '' ? $recipientName : null,
                    'amount' => $amount,
                    'transfer_note' => $note !== '' ? $note : null,
                    'requested_by' => $operation->requested_by,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Không lưu được lịch sử chuyển tiền.', [
                'operation_id' => $operation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Chuỗi stdout từ DWIN command API (cat / base64 / …) — cùng các tầng lồng nhau như dump UI.
     *
     * @param  array<string, mixed>  $jsonRoot  Toàn bộ JSON phản hồi (giống $result['data'] từ DuoPlusApi::post).
     */
    private function extractDuoPlusCommandStdout(array $jsonRoot): string
    {
        foreach (['data.content', 'data.data.content', 'data.data.data.content'] as $path) {
            $raw = (string) data_get($jsonRoot, $path, '');
            if ($raw !== '') {
                return $raw;
            }
        }

        return '';
    }

    private function captureReceiptScreenshotPng(
        DeviceOperation $operation,
        DuoPlusApi $duoPlusApi,
        Device $device,
    ): ?string {
        $commands = [
            'screencap -p /sdcard/receipt.png && base64 /sdcard/receipt.png',
            'exec-out screencap -p | base64',
        ];

        foreach ($commands as $cmdIdx => $command) {
            $attempts = 0;
            $lastFailReason = '';
            do {
                $attempts++;
                try {
                    $result = $duoPlusApi->command(
                        $device->duo_api_key,
                        $device->image_id,
                        $command,
                    );
                    if (! $result['ok']) {
                        $lastFailReason = $result['message'] !== '' ? $result['message'] : 'DWIN command lỗi.';
                        if ($attempts < 3 && $this->isTransientCommandTimeout($lastFailReason)) {
                            usleep(400_000);

                            continue;
                        }
                        break;
                    }

                    $b64 = $this->extractDuoPlusCommandStdout($result['data']);
                    $b64 = preg_replace('/\\s+/', '', $b64) ?? '';
                    if ($b64 === '') {
                        $lastFailReason = 'Không có content base64 từ DuoPlus.';
                        $this->log($operation, 'receipt_capture', $lastFailReason.' (lệnh '.($cmdIdx + 1)."/{$attempts})", 'warning', [
                            'keys' => array_keys((array) ($result['data'] ?? [])),
                        ]);
                        break;
                    }

                    $binary = base64_decode($b64, true);
                    if ($binary === false) {
                        $lastFailReason = 'Base64 decode thất bại.';
                        $this->log($operation, 'receipt_capture', $lastFailReason, 'warning');
                        break;
                    }

                    $img = @imagecreatefromstring($binary);
                    if (! $img) {
                        $lastFailReason = 'Không đọc được ảnh từ binary (PNG không hợp lệ hoặc bị cắt).';
                        $this->log($operation, 'receipt_capture', $lastFailReason, 'warning');
                        break;
                    }
                    imagedestroy($img);

                    return $binary;
                } catch (Throwable $e) {
                    $lastFailReason = 'Exception: '.$e->getMessage();
                    $this->log($operation, 'receipt_capture', 'Lỗi chụp biên lai: '.$e->getMessage(), 'warning');
                    break;
                }
            } while ($attempts < 3);
            if ($lastFailReason !== '' && $cmdIdx === count($commands) - 1) {
                $this->log($operation, 'receipt_capture', 'Hết phương án chụp biên lai: '.$lastFailReason, 'warning');
            }
        }

        return null;
    }

    private function buildTelegramReceiptCaption(
        Device $device,
        string $channel,
        string $fromBank,
        string $account,
        string $toBank,
        int $amount,
        string $recipientName,
        string $note,
    ): string {
        $machineCode = trim((string) $device->image_id);
        $machineName = trim((string) ($device->name ?? ''));
        $channelLabel = match ($channel) {
            'baca' => 'Bac A Bank',
            'pg' => 'PG Bank',
            default => $channel,
        };

        $lines = [
            'Chuyen khoan thanh cong',
            'Ma may: '.($machineCode !== '' ? $machineCode : '-'),
            'Ten may: '.($machineName !== '' ? $machineName : '-'),
            'Kenh CK: '.$channelLabel,
            'Ngan hang gui: '.$fromBank,
            'Ngan hang nhan: '.($toBank !== '' ? $toBank : '-'),
            'So tai khoan: '.($account !== '' ? $account : '-'),
            'Nguoi nhan: '.($recipientName !== '' ? $recipientName : '-'),
            'So tien: '.number_format($amount, 0, ',', '.').' VND',
            'Noi dung: '.($note !== '' ? $note : '-'),
            'Thoi gian: '.now()->format('Y-m-d H:i:s'),
        ];

        return mb_substr(implode("\n", $lines), 0, 1000);
    }

    private function sendReceiptToTelegram(DeviceOperation $operation, string $channel, ?string $png, string $caption): bool
    {
        if (! is_string($png) || $png === '') {
            $this->log($operation, 'receipt_capture', 'Không gửi Telegram vì chưa có ảnh biên lai.', 'warning');

            return false;
        }

        $token = (string) config('services.telegram.token', '');
        $chatId = (string) config('services.telegram.chat_id', '');
        if ($token === '' || $chatId === '') {
            $this->log($operation, 'receipt_capture', 'Thiếu TELEGRAM_TOKEN hoặc TELEGRAM_CHAT_ID.', 'warning');

            return false;
        }

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'receipt_');
            if (! is_string($tmp) || $tmp === '') {
                $this->log($operation, 'receipt_capture', 'Không tạo được file tạm để gửi Telegram.', 'warning');

                return false;
            }
            file_put_contents($tmp, $png);
            $response = Http::timeout(45)
                ->asMultipart()
                ->attach('photo', file_get_contents($tmp) ?: '', "receipt-{$channel}-op-{$operation->id}.png")
                ->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                    'chat_id' => $chatId,
                    'caption' => $caption !== '' ? $caption : "[$channel] Chuyen khoan thanh cong.",
                ]);

            @unlink($tmp);

            if (! $response->successful() || data_get($response->json(), 'ok') !== true) {
                $this->log($operation, 'receipt_capture', 'Gửi Telegram thất bại.', 'warning', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->log($operation, 'receipt_capture', 'Lỗi gửi Telegram: '.$e->getMessage(), 'warning');

            return false;
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

            $b64 = $this->extractDuoPlusCommandStdout($result['data']);
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
     * Gửi `input tap X Y` với X,Y nguyên — DuoPlus/ADB thường không nhận tọa độ thập phân (tap nút xóa có thể không chạy).
     *
     * @param  array{0?: mixed, 1?: mixed}  $xy
     */
    private function tap(DuoPlusApi $duoPlusApi, Device $device, DeviceOperation $operation, string $stage, array $xy): void
    {
        $pair = $this->normalizeTapPair($xy);
        if ($pair === null) {
            $this->log($operation, $stage, 'Tọa độ tap không hợp lệ (cần [x,y]).', 'error', ['xy' => $xy]);
            throw new \RuntimeException('Tọa độ tap không hợp lệ: '.$stage);
        }

        $this->sendAdb($duoPlusApi, $device, $operation, $stage, 'input tap '.$pair[0].' '.$pair[1]);
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

    /**
     * Tap nút mở che số dư (mắt) trên home Bắc Á.
     * Lần 1: tap đơn tại điểm chính. Lần 2: double tap cùng điểm (một số bản app cần). Lần 3: tọa độ alt).
     *
     * @param  array<string, mixed>  $tapCfg
     * @param  array<string, mixed>  $timing
     */
    private function tapBacaRevealBalanceForAttempt(
        DuoPlusApi $duoPlusApi,
        Device $device,
        DeviceOperation $operation,
        array $tapCfg,
        array $timing,
        int $attempt,
    ): void {
        $attempt = max(1, min(3, $attempt));

        $primary = $this->normalizeTapPair($tapCfg['reveal_balance'] ?? null) ?? [962, 1000];
        $alt = isset($tapCfg['reveal_balance_alt']) ? $this->normalizeTapPair($tapCfg['reveal_balance_alt']) : null;

        if ($attempt === 1) {
            $w = (float) ($timing['wait_before_reveal_balance'] ?? 1.5);
            if ($w > 0) {
                $this->sleepCloud($duoPlusApi, $device, $operation, 'wait_before_reveal_balance', $w);
            }
        } else {
            $this->pauseLocal((float) ($timing['wait_between_reveal_retries'] ?? 0.9));
        }

        $xy = ($attempt === 3 && $alt !== null) ? $alt : $primary;

        $this->tap($duoPlusApi, $device, $operation, "reveal_balance_try{$attempt}", $xy);

        if ($attempt === 2) {
            $this->pauseLocal((float) ($timing['wait_between_reveal_double_tap'] ?? 0.22));
            $this->tap($duoPlusApi, $device, $operation, "reveal_balance_try{$attempt}_2", $xy);
        }

        $this->sleepCloud(
            $duoPlusApi,
            $device,
            $operation,
            'wait_reveal_balance',
            (float) ($timing['wait_reveal_balance'] ?? 1.8),
        );
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function normalizeTapPair(mixed $xy): ?array
    {
        if (! is_array($xy) || ! isset($xy[0], $xy[1])) {
            return null;
        }

        return [
            (int) round((float) $xy[0]),
            (int) round((float) $xy[1]),
        ];
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
            $message = $result['message'] !== '' ? $result['message'] : 'DWIN command lỗi.';
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

        $content = $this->extractDuoPlusCommandStdout($result['data']);

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
     * @param  array<string, string>  $map  bank_name_map config (override cứng)
     * @param  list<string>  $bankList  bank_list config
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

    private function pgTransferDumpLooksSuccessful(string $dump): bool
    {
        return PgTransferSuccessfulUiDump::matches($dump);
    }

    /**
     * Nhập PIN / Smart OTP PG: đọc `pg_pin` (6 số), xóa sạch ô rồi gõ đúng một lần — không tự thử lại PIN (tránh khóa Smart OTP).
     *
     * Lưu ý: tap **hai lần** liên tiếp cùng `otp_focus` dễ trúng phím trên bàn phím số PG → sinh thêm một chữ số trước chuỗi adb; mặc định chỉ **một** tap + `wait_after_otp_focus`.
     *
     * @param  array{0: int, 1: int}  $otpFocus
     */
    private function submitPgTransferOtpDigits(
        Device $device,
        DuoPlusApi $duoPlusApi,
        DeviceOperation $operation,
        array $timing,
        bool $requireFaceScan,
        array $otpFocus,
    ): void {
        $pinSix = preg_replace('/\D+/', '', (string) $device->pg_pin) ?? '';
        if (strlen($pinSix) !== 6) {
            throw new \RuntimeException('PIN PG không hợp lệ (cần 6 số trong pg_pin).');
        }

        $this->log($operation, 'pg_otp_strategy', 'Smart OTP: 1 tap focus (mặc định), xóa ô rồi gõ đúng 6 số pg_pin — tránh tap đè phím số.', 'info');

        $this->tap($duoPlusApi, $device, $operation, 'otp_focus', $otpFocus);
        $this->pauseLocal((float) ($timing['wait_after_otp_focus'] ?? 0.38));
        if (! empty($timing['otp_double_tap_focus'])) {
            $this->tap($duoPlusApi, $device, $operation, 'otp_focus_2', $otpFocus);
            $this->pauseLocal(0.18);
        }

        $selectAllCycles = max(0, min(4, (int) ($timing['otp_clear_select_all_cycles'] ?? 2)));
        for ($c = 0; $c < $selectAllCycles; $c++) {
            $this->sendAdb($duoPlusApi, $device, $operation, "otp_clear_sel_{$c}", 'input keyevent 277');
            $this->pauseLocal(0.08);
            $this->sendAdb($duoPlusApi, $device, $operation, "otp_clear_del_{$c}", 'input keyevent 67');
            $this->pauseLocal(0.08);
        }

        $burst = max(0, min(24, (int) ($timing['otp_field_clear_del_burst'] ?? 12)));
        if ($burst > 0) {
            $per = 12;
            $chunk = 0;
            for ($left = $burst; $left > 0; $left -= $n) {
                $n = min($per, $left);
                $cmd = implode(' && ', array_fill(0, $n, 'input keyevent 67'));
                $this->sendAdb($duoPlusApi, $device, $operation, 'otp_field_del_burst_'.$chunk, $cmd);
                $this->pauseLocal(0.07);
                $chunk++;
            }
        }

        if ($requireFaceScan) {
            for ($del = 0; $del < 4; $del++) {
                $this->sendAdb($duoPlusApi, $device, $operation, "otp_livestream_tail_del_{$del}", 'input keyevent 67');
                $this->pauseLocal(0.04);
            }
            $this->pauseLocal(0.08);
        }

        $this->pauseLocal(0.12);

        $useNumpad = ! empty($timing['otp_use_numpad_keycodes']);
        $keycodeBase = ($requireFaceScan || $useNumpad) ? 144 : 7;
        $refocusEach = ! empty($timing['otp_refocus_each_digit']);

        for ($i = 0; $i < 6; $i++) {
            $d = (int) $pinSix[$i];
            if ($refocusEach) {
                $this->tap($duoPlusApi, $device, $operation, "otp_refocus_{$i}", $otpFocus);
                $this->pauseLocal(0.06);
            }
            $this->sendAdb($duoPlusApi, $device, $operation, "otp_digit_{$d}_{$i}", 'input keyevent '.($keycodeBase + $d));
            $this->pauseLocal((float) ($timing['wait_otp_digit_pg'] ?? 0.12));
        }

        if (! empty($timing['otp_send_enter_after_digits'])) {
            $this->sendAdb($duoPlusApi, $device, $operation, 'otp_keyevent_enter', 'input keyevent 66');
            $this->pauseLocal(0.25);
        }
    }

    /**
     * PG — nhập nội dung CK: tap vùng xóa (nếu có tọa độ) → tap ô nhập → `adb input text` → tap blur.
     *
     * @param  array{0: float|int, 1: float|int}|null  $clearTap
     * @param  array{0: float|int, 1: float|int}  $focusTap
     * @param  array{0: float|int, 1: float|int}  $blurTap
     */
    private function fillPgTransferNoteTwoTapFlow(
        DuoPlusApi $duoPlusApi,
        Device $device,
        DeviceOperation $operation,
        array $timing,
        ?array $clearTap,
        array $focusTap,
        array $blurTap,
        string $stagePrefix,
        string $safeNote,
    ): void {
        if ($clearTap !== null) {
            $repeats = max(1, min(5, (int) ($timing['note_clear_tap_repeat'] ?? 2)));
            $between = (float) ($timing['note_wait_between_clear_taps'] ?? 0.22);
            $this->log($operation, "{$stagePrefix}_note_clear_start", 'PG: bắt đầu tap nút xóa nội dung CK.', 'info', [
                'repeats' => $repeats,
                'xy_int' => $this->normalizeTapPair($clearTap),
            ]);
            for ($r = 0; $r < $repeats; $r++) {
                $this->tap($duoPlusApi, $device, $operation, "{$stagePrefix}_note_clear_old_{$r}", $clearTap);
                if ($r < $repeats - 1) {
                    $this->pauseLocal($between);
                }
            }
            $this->pauseLocal((float) ($timing['note_wait_after_clear_tap'] ?? $timing['wait_after_note_clear_first_tap'] ?? 0.35));
        }
        $this->tap($duoPlusApi, $device, $operation, "{$stagePrefix}_note_focus_input", $focusTap);
        $this->pauseLocal((float) ($timing['note_wait_after_focus_tap'] ?? $timing['wait_after_note_focus_tap'] ?? 0.28));
        $inputStage = $stagePrefix === 'pg_ln' ? 'input_note' : "{$stagePrefix}_input_note";
        $this->sendAdb($duoPlusApi, $device, $operation, $inputStage, $this->inputTextCommand($safeNote));
        $this->pauseLocal((float) ($timing['note_after_input_pause'] ?? 0.15));
        $this->tap($duoPlusApi, $device, $operation, "{$stagePrefix}_note_blur", $blurTap);
    }

    private function bacaTransferDumpLooksSuccessful(string $dump): bool
    {
        return $this->containsAny($dump, [
            'thành công', 'thanh cong', 'giao dịch thành công', 'chuyển tiền thành công',
            'chuyen tien thanh cong', 'gd thành công', 'gd thanh cong', 'hoàn tất', 'hoan tat',
        ]);
    }

    /**
     * PG chuyển khoản nội bộ (trong hệ thống): tap luồng riêng + tối đa N lần "Tiếp tục" nếu gặp lỗi tạm thời.
     *
     * @return array{ok: bool, message: string}|null null = qua bước này, tiếp tục phase xác thực như PG thường
     */
    private function runPgInternalTransferScreens(
        Device $device,
        DuoPlusApi $duoPlusApi,
        DeviceOperation $operation,
        array $tap,
        string $account,
        int $amount,
        string $safeNote,
    ): ?array {
        $cfg = (array) config('pgbank');
        $icfg = (array) ($cfg['internal_transfer'] ?? []);
        $itap = (array) ($icfg['tap'] ?? []);
        $itim = (array) ($icfg['timing'] ?? []);
        $noteTiming = array_merge((array) ($cfg['timing'] ?? []), $itim);
        $needle = mb_strtolower((string) ($icfg['temporary_error_needle'] ?? 'giao dịch không thực hiện được trong lúc này'));
        $maxAttempts = max(1, (int) ($icfg['continue_max_attempts'] ?? 4));

        $xy = static function (array $arr, string $key, array $fallback): array {
            $v = $arr[$key] ?? null;

            return is_array($v) && count($v) >= 2 ? [(float) $v[0], (float) $v[1]] : $fallback;
        };

        $this->tap($duoPlusApi, $device, $operation, 'pg_int_in_system', $xy($itap, 'in_system', [176, 446]));
        $this->pauseLocal((float) ($itim['wait_after_in_system'] ?? 1.0));
        $this->tap($duoPlusApi, $device, $operation, 'pg_int_close_modal', $xy($itap, 'close_modal', [564, 1127]));
        $this->pauseLocal((float) ($itim['wait_after_close_modal'] ?? 1.0));

        // Nội dung nội bộ: tap xóa → tap ô → `input text` → tap blur (tọa độ `internal_transfer.tap.*`).
        $clearNoteTap = $xy($itap, 'clear_note', [948.2, 1345]);
        $noteTap = $xy($itap, 'note', [900, 1345]);
        $noteBlurTap = $xy($itap, 'blur_corner', [20, 604]);
        $clearTapForFlow = (($icfg['clear_note_tap_enabled'] ?? true) !== false) ? $clearNoteTap : null;
        if ($clearTapForFlow === null) {
            $this->log($operation, 'pg_int_note_clear_skipped', 'PG nội bộ: bỏ tap nút xóa (clear_note_tap_enabled=false).', 'warning');
        } else {
            $this->log($operation, 'pg_int_note_clear_xy', 'PG nội bộ: chuẩn bị tap nút xóa nội dung CK.', 'info', [
                'clear_xy_raw' => $clearNoteTap,
                'clear_xy_int' => $this->normalizeTapPair($clearNoteTap),
            ]);
        }
        $this->log($operation, 'pg_int_note_step', 'Nội dung nội bộ: tap xóa → tap ô → input text → blur.', 'info', [
            'clear_xy' => $clearNoteTap,
            'note_xy' => $noteTap,
            'blur_xy' => $noteBlurTap,
            'clear_tap_enabled' => $clearTapForFlow !== null,
        ]);
        $this->fillPgTransferNoteTwoTapFlow(
            $duoPlusApi,
            $device,
            $operation,
            $noteTiming,
            $clearTapForFlow,
            $noteTap,
            $noteBlurTap,
            'pg_int',
            $safeNote,
        );
        $this->pauseLocal((float) ($itim['wait_after_field_blur'] ?? 0.35));

        $this->tap($duoPlusApi, $device, $operation, 'pg_int_focus_amount', $xy($itap, 'amount', [276, 1106]));
        $this->pauseLocal(0.2);
        $this->sendAdb($duoPlusApi, $device, $operation, 'pg_int_clear_amount', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'pg_int_input_amount', $this->inputTextCommand((string) $amount));
        $this->pauseLocal((float) ($itim['wait_after_field_blur'] ?? 0.35));
        $this->tap($duoPlusApi, $device, $operation, 'pg_int_blur_after_amount', $xy($itap, 'blur_corner', [18, 600]));
        $this->pauseLocal((float) ($itim['wait_after_field_blur'] ?? 0.35));

        $this->tap($duoPlusApi, $device, $operation, 'pg_int_focus_account', $xy($itap, 'account', [337, 908]));
        $this->pauseLocal(0.2);
        $this->sendAdb($duoPlusApi, $device, $operation, 'pg_int_clear_account', 'input keyevent 277');
        $this->sendAdb($duoPlusApi, $device, $operation, 'pg_int_input_account', $this->inputTextCommand($account));
        $this->pauseLocal((float) ($itim['wait_after_field_blur'] ?? 0.35));
        $this->tap($duoPlusApi, $device, $operation, 'pg_int_blur_after_account', $xy($itap, 'blur_corner', [18, 600]));
        $this->pauseLocal((float) ($itim['wait_before_continue'] ?? 1.5));

        $continueTap = $xy($itap, 'continue', [516, 1782]);
        $dismissTap = $xy($itap, 'error_dismiss', [542, 1151]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->tap($duoPlusApi, $device, $operation, 'pg_int_continue_'.$attempt, $continueTap);
            $this->pauseLocal((float) ($itim['wait_after_continue_dump'] ?? 1.2));
            $dump = $this->dumpUiText($duoPlusApi, $device, $operation);
            $low = mb_strtolower($dump);
            if (! str_contains($low, $needle)) {
                return null;
            }
            if ($attempt >= $maxAttempts) {
                return ['ok' => false, 'message' => 'PG nội bộ: vẫn hiện lỗi tạm thời sau '.$maxAttempts.' lần thử Tiếp tục.'];
            }
            $this->tap($duoPlusApi, $device, $operation, 'pg_int_error_dismiss_'.$attempt, $dismissTap);
            $this->pauseLocal(0.45);
        }

        return ['ok' => false, 'message' => 'PG nội bộ: không qua được bước Tiếp tục.'];
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
        $this->maybeBroadcastAfterLog($operation);
    }

    /**
     * Giới hạn tần suất broadcast khi tạo nhiều log liên tiếp (tránh spam Reverb; UI vẫn nhận cập nhật vài lần/giây khi đang chạy).
     */
    private function maybeBroadcastAfterLog(DeviceOperation $operation): void
    {
        $now = microtime(true);

        if (($now - $this->lastBroadcastFromLogAt) < self::LOG_BROADCAST_MIN_INTERVAL_SEC) {
            return;
        }

        $this->lastBroadcastFromLogAt = $now;
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
