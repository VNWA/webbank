<?php

namespace App\Support;

/**
 * Parse số dư (VND) từ chuỗi UI dump (accessibility / OCR / uiautomator XML).
 *
 * PG và Bắc Á thường hiển thị số có phẩy nhóm nghìn, có thể **không đều**
 * (ví dụ `100,000` hoặc `1000,000,000`). Cách xử lý: lấy **toàn bộ chữ số**
 * trong phần số được regex bắt (bỏ phẩy/chấm/khoảng trắng) → một số nguyên VND.
 *
 * Dump `uiautomator` là XML — cần làm phẳng `text=` / `content-desc=`.
 *
 * - **PG** (`pgbankApp.pgbank.com.vn`): định dạng số tiền tham chiếu `18,300,000 VND`
 *   (phẩy nhóm nghìn + `VND`). Trên màn hình dump thường gặp **một** `content-desc`
 *   gộp nhãn + số, ví dụ `content-desc="Số dư: 18,300,000 VND"` (cùng kiểu Bắc Á).
 * - **Bắc Á** (`com.bab.retailUAT`): ví dụ `content-desc="Số dư: 2,480,000 VND"`.
 */
final class BalanceFromUiDumpParser
{
    /**
     * Phần số trước `VND`/`đ` — có thể dài khi nhiều nhóm (kể cả nhóm không chuẩn 3 chữ số).
     */
    private const AMOUNT_BODY = '[0-9][0-9\.,\s]{0,45}';

    /**
     * Ngưỡng trên (VND): trên mức này coi như parse nhầm (ID, STK, v.v.).
     */
    public const MAX_PLAUSIBLE_VND = 1_000_000_000_000_000;

    /**
     * @var list<string>
     */
    private const KEYWORD_NEEDLES = [
        'số dư',
    ];

    public static function parse(string $dump): ?int
    {
        $sources = [];
        if (self::looksLikeUidumpXml($dump)) {
            $flat = self::flattenUidumpXmlToText($dump);
            if ($flat !== '') {
                $sources[] = $flat;
            }
        }
        $sources[] = $dump;

        foreach ($sources as $source) {
            $parsed = self::parsePlainText($source);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Chuyển một đoạn hiển thị tiền (phẩy/chấm tùy app) sang số nguyên VND.
     * Ví dụ: `100,000` → 100000; `1000,000,000` → 1000000000.
     */
    public static function digitsOnlyToVndInt(string $raw): ?int
    {
        $num = preg_replace('/[^0-9]/', '', $raw) ?? '';
        if ($num === '' || ! ctype_digit($num)) {
            return null;
        }
        $v = (int) $num;

        return self::isPlausible($v) ? $v : null;
    }

    private static function looksLikeUidumpXml(string $dump): bool
    {
        return str_contains($dump, '<hierarchy')
            || str_contains($dump, '<node')
            || str_contains($dump, '<?xml');
    }

    /**
     * Gom nội dung đọc được từ XML (thứ tự xuất hiện trong file).
     */
    private static function flattenUidumpXmlToText(string $xml): string
    {
        $parts = [];
        if (preg_match_all('/\btext="([^"]*)"/u', $xml, $m)) {
            foreach ($m[1] as $t) {
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
        }
        if (preg_match_all('/\bcontent-desc="([^"]*)"/u', $xml, $m2)) {
            foreach ($m2[1] as $t) {
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
        }

        return implode("\n", $parts);
    }

    private static function parsePlainText(string $dump): ?int
    {
        $text = mb_strtolower($dump);
        $text = str_replace('*', '', $text);

        foreach (self::KEYWORD_NEEDLES as $needle) {
            $p = mb_strrpos($text, mb_strtolower($needle));
            if ($p === false) {
                continue;
            }
            $window = mb_substr($text, (int) $p, 320);
            $parsed = self::parseFromKeywordWindow($window);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $anchorPos = mb_strpos($text, 'tài khoản thanh toán');
        if ($anchorPos !== false) {
            $window = mb_substr($text, (int) $anchorPos, 900);
            $parsed = self::firstPlausibleCurrencyAmount($window);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $amounts = self::allPlausibleCurrencyAmounts($text);
        if (count($amounts) === 1) {
            return $amounts[0];
        }

        if (preg_match('/(số\s*dư|so\s*du)[^0-9]{0,80}('.self::AMOUNT_BODY.')/u', $text, $m)) {
            $v = self::digitsOnlyToVndInt($m[2] ?? '');
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    private static function parseFromKeywordWindow(string $window): ?int
    {
        if (preg_match('/(số\s*dư|so\s*du|khả\s*dụng|kha\s*dung|balance)[^0-9]{0,100}\b0\s*(đ|₫|vnd|vnđ)\b/u', $window)) {
            return 0;
        }

        if (preg_match('/\b0\s*(đ|₫|vnd|vnđ)\b/u', $window)) {
            return 0;
        }

        return self::firstPlausibleCurrencyAmount($window);
    }

    private static function firstPlausibleCurrencyAmount(string $window): ?int
    {
        if (preg_match_all('/('.self::AMOUNT_BODY.')\s*(vnd|vnđ|đ|₫)/u', $window, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $v = self::digitsOnlyToVndInt($m[1] ?? '');
                if ($v !== null) {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function allPlausibleCurrencyAmounts(string $text): array
    {
        $out = [];
        if (preg_match_all('/('.self::AMOUNT_BODY.')\s*(vnd|vnđ|đ|₫)/u', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $v = self::digitsOnlyToVndInt($m[1] ?? '');
                if ($v !== null) {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    private static function isPlausible(int $v): bool
    {
        return $v >= 0 && $v <= self::MAX_PLAUSIBLE_VND;
    }
}
