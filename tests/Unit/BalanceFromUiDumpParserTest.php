<?php

namespace Tests\Unit;

use App\Support\BalanceFromUiDumpParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BalanceFromUiDumpParserTest extends TestCase
{
    public function test_parses_zero_after_keyword_with_currency(): void
    {
        $dump = 'Tài khoản\nSố dư: 0 đ\nPG Bank';
        $this->assertSame(0, BalanceFromUiDumpParser::parse($dump));
    }

    public function test_parses_zero_compact_after_so_du(): void
    {
        $dump = "so du\n0đ\n";
        $this->assertSame(0, BalanceFromUiDumpParser::parse($dump));
    }

    public function test_prefers_amount_in_keyword_window_not_global_max(): void
    {
        $dump = <<<'TXT'
        stk 0123456789012345678
        so du
        0 đ
        khác 8000000000 đ phí
        TXT;

        $this->assertSame(0, BalanceFromUiDumpParser::parse($dump));
    }

    public function test_does_not_use_fallback_max_on_raw_digit_blobs(): void
    {
        $dump = '0123456789012 9999999999 không có từ khoá tiền tệ rõ';
        $this->assertNull(BalanceFromUiDumpParser::parse($dump));
    }

    public function test_single_currency_amount_on_screen(): void
    {
        $dump = 'Màn hình chỉ có một dòng: 50.000 ₫';
        $this->assertSame(50000, BalanceFromUiDumpParser::parse($dump));
    }

    public function test_pg_style_dot_grouped_amount_with_vnd(): void
    {
        $dump = "số dư\n18.300.000 VND\n";
        $this->assertSame(18_300_000, BalanceFromUiDumpParser::parse($dump));
    }

    public function test_pg_style_comma_grouped_amount_with_vnd(): void
    {
        $dump = "so du\n18,300,000 vnd\n";
        $this->assertSame(18_300_000, BalanceFromUiDumpParser::parse($dump));
    }

    /** Định dạng tham chiếu PG: một dòng đúng như trên app (phẩy + VND). */
    public function test_pg_reference_line_is_18_300_000_vnd(): void
    {
        $this->assertSame(18_300_000, BalanceFromUiDumpParser::parse('18,300,000 VND'));
    }

    /** Định dạng tham chiếu Bắc Á: cùng kiểu phẩy + VND như PG. */
    public function test_baca_reference_line_is_2_480_000_vnd(): void
    {
        $this->assertSame(2_480_000, BalanceFromUiDumpParser::parse('2,480,000 VND'));
    }

    public function test_digits_only_accepts_irregular_comma_grouping(): void
    {
        $this->assertSame(100_000, BalanceFromUiDumpParser::digitsOnlyToVndInt('100,000'));
        $this->assertSame(1_000_000_000, BalanceFromUiDumpParser::digitsOnlyToVndInt('1000,000,000'));
    }

    public function test_parses_uidump_xml_nodes_with_pg_style_amount(): void
    {
        $xml = <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<hierarchy>
  <node text="Số dư" />
  <node text="18,300,000 VND" />
</hierarchy>
XML;
        $this->assertSame(18_300_000, BalanceFromUiDumpParser::parse($xml));
    }

    /** PG: một node — content-desc gộp "Số dư:" + định dạng `18,300,000 VND` (tham chiếu app PG). */
    public function test_pg_balance_in_single_content_desc_số_dư_colon_amount_vnd(): void
    {
        $xml = '<hierarchy package="pgbankApp.pgbank.com.vn"><node content-desc="Số dư: 18,300,000 VND" /></hierarchy>';
        $this->assertSame(18_300_000, BalanceFromUiDumpParser::parse($xml));
    }

    /** Bắc Á: một node — content-desc gộp "Số dư:" và số tiền (như device-2-sample.xml). */
    public function test_baca_balance_in_single_content_desc_số_dư_colon_amount_vnd(): void
    {
        $xml = '<hierarchy><node content-desc="Số dư: 2,480,000 VND" /></hierarchy>';
        $this->assertSame(2_480_000, BalanceFromUiDumpParser::parse($xml));
    }

    public function test_pg_available_balance_keyword_with_grouped_vnd(): void
    {
        $dump = "PG Bank\navailable balance\n12,345,678 VND\n";
        $this->assertSame(12_345_678, BalanceFromUiDumpParser::parse($dump));
    }

    #[DataProvider('implausibleProvider')]
    public function test_rejects_implausible_magnitudes(string $dump): void
    {
        $this->assertNull(BalanceFromUiDumpParser::parse($dump));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function implausibleProvider(): iterable
    {
        $tooHigh = (string) (BalanceFromUiDumpParser::MAX_PLAUSIBLE_VND + 1);

        yield 'over max' => ["số dư\n{$tooHigh} đ\n"];
    }
}
