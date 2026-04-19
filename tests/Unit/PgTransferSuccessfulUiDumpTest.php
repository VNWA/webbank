<?php

namespace Tests\Unit;

use App\Support\PgTransferSuccessfulUiDump;
use Tests\TestCase;

class PgTransferSuccessfulUiDumpTest extends TestCase
{
    public function test_matches_classic_success_phrases(): void
    {
        $this->assertTrue(PgTransferSuccessfulUiDump::matches('giao dịch thành công'));
        $this->assertTrue(PgTransferSuccessfulUiDump::matches('chuyen khoan thanh cong'));
        $this->assertTrue(PgTransferSuccessfulUiDump::matches('hoàn tất giao dịch'));
    }

    public function test_matches_internal_transfer_style_phrases(): void
    {
        $this->assertTrue(PgTransferSuccessfulUiDump::matches('chuyển khoản trong hệ thống thành công'));
        $this->assertTrue(PgTransferSuccessfulUiDump::matches('mã giao dịch: 12345'));
        $this->assertTrue(PgTransferSuccessfulUiDump::matches('giao dịch đã được thực hiện'));
    }

    public function test_rejects_unrelated_text(): void
    {
        $this->assertFalse(PgTransferSuccessfulUiDump::matches('nhập mật khẩu smart otp'));
        $this->assertFalse(PgTransferSuccessfulUiDump::matches('đang xử lý'));
    }
}
