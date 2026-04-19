<?php

namespace App\Support;

/**
 * Nhận diện màn hình thành công từ nội dung UI dump (đã lower-case) sau chuyển tiền PG.
 */
final class PgTransferSuccessfulUiDump
{
    /**
     * @var list<string>
     */
    private const NEEDLES = [
        'thành công',
        'thanh cong',
        'giao dịch thành công',
        'chuyển tiền thành công',
        'chuyen tien thanh cong',
        'hoàn tất',
        'hoan tat',
        'gd thành công',
        'gd thanh cong',
        'đã gửi',
        'da gui',
        'chuyển khoản thành công',
        'chuyen khoan thanh cong',
        'trong hệ thống thành công',
        'trong he thong thanh cong',
        'giao dịch đã được thực hiện',
        'giao dich da duoc thuc hien',
        'thực hiện thành công',
        'thuc hien thanh cong',
        'mã giao dịch',
        'ma giao dich',
        'biên lai',
        'bien lai',
        'giao dịch thành công.',
        'transaction success',
    ];

    public static function matches(string $dumpLowercased): bool
    {
        foreach (self::NEEDLES as $needle) {
            if (str_contains($dumpLowercased, $needle)) {
                return true;
            }
        }

        return false;
    }
}
