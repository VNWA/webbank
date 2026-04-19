<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

/**
 * Danh sách NH: căn theo `pgbank.bank_list` + `bacabank.bank_list` (botBank).
 * `pg_name` / `baca_name` là chuỗi gõ tìm trong app PG / Bắc Á (nhiều nhãn cùng NH → nối bằng " | ").
 */
class BankSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{code: string, name: string, short_name: string, pg_name: string, baca_name: string}> $banks */
        $banks = [
            ['code' => 'ACB', 'name' => 'Ngân hàng TMCP Á Châu', 'short_name' => 'ACB', 'pg_name' => 'A CHAU (ACB)', 'baca_name' => 'ACB'],
            ['code' => 'AGRIBANK', 'name' => 'Ngân hàng NN&PTNT Việt Nam', 'short_name' => 'Agribank', 'pg_name' => 'NONG NGHIEP VA PHAT TRIEN NONG THON VIET NAM (AGRIBANK)', 'baca_name' => 'AGRIBANK'],
            ['code' => 'BAB', 'name' => 'Ngân hàng TMCP Bắc Á', 'short_name' => 'BacABank', 'pg_name' => 'BAC A (BAC A -HSC)', 'baca_name' => ''],
            ['code' => 'BANGKOK_HCM', 'name' => 'Bangkok Bank — CN HCM', 'short_name' => 'Bangkok Bank HCM', 'pg_name' => 'BANGKOK BANK HCM', 'baca_name' => 'BANGKOK BANK HCM'],
            ['code' => 'BANGKOK_HN', 'name' => 'Bangkok Bank — CN Hà Nội', 'short_name' => 'Bangkok Bank HN', 'pg_name' => 'Bangkok bank chi nhanh ha noi', 'baca_name' => 'BANGKOK BANK HN'],
            ['code' => 'BVB', 'name' => 'Ngân hàng TMCP Bảo Việt', 'short_name' => 'BaoVietBank', 'pg_name' => 'BAN VIET (BVBank)', 'baca_name' => 'BVBANK'],
            ['code' => 'BAOVIETBANK', 'name' => 'Bảo Việt', 'short_name' => 'BaoVietBank', 'pg_name' => 'BAO VIET', 'baca_name' => 'BAOVIETBANK'],
            ['code' => 'BIDV', 'name' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam', 'short_name' => 'BIDV', 'pg_name' => 'DAU TU VA PHAT TRIEN VIET NAM (BIDV)', 'baca_name' => 'BIDV|BIDC'],
            ['code' => 'BOCHK', 'name' => 'Bank of China — CN TP.HCM', 'short_name' => 'BOCHK', 'pg_name' => 'Ngan hang Bank of China - CN.TPHCM (BOCHK)', 'baca_name' => 'BOCHK'],
            ['code' => 'BPCE_HCM', 'name' => 'BPCE IOM — CN HCM', 'short_name' => 'BPCE IOM', 'pg_name' => 'BPCE', 'baca_name' => 'BPCE IOM HCM'],
            ['code' => 'BUSAN_HCM', 'name' => 'Ngân hàng Busan — CN HCM', 'short_name' => 'Busan Bank', 'pg_name' => 'Busan', 'baca_name' => 'BUSAN HCM'],
            ['code' => 'CAKE', 'name' => 'CAKE by VPBank', 'short_name' => 'CAKE', 'pg_name' => 'TMCP Viet Nam Thinh Vuong - Ngan hang so CAKE by VPBank (CAKE)', 'baca_name' => 'CAKE'],
            ['code' => 'CIMB', 'name' => 'Ngân hàng TNHH MTV CIMB Việt Nam', 'short_name' => 'CIMB', 'pg_name' => 'CIMB BANK (CIMB)', 'baca_name' => 'CIMB BANK'],
            ['code' => 'COOPBANK', 'name' => 'Ngân hàng Hợp tác xã Việt Nam', 'short_name' => 'Co-op Bank', 'pg_name' => 'Co-op', 'baca_name' => 'COOPBANK'],
            ['code' => 'CTBC_HCM', 'name' => 'CTBC Bank — CN HCM', 'short_name' => 'CTBC', 'pg_name' => 'CTBC', 'baca_name' => 'CTBC HCM'],
            ['code' => 'CUB_HCM', 'name' => 'Cathay United Bank — CN HCM', 'short_name' => 'CUB HCM', 'pg_name' => 'Cathay United Bank CN HCM (CUBHCM)', 'baca_name' => 'CUBHCM'],
            ['code' => 'DAEGU_HCM', 'name' => 'Daegu Bank — CN HCM', 'short_name' => 'Daegu', 'pg_name' => 'Daegu', 'baca_name' => 'DAEGU HCM'],
            ['code' => 'DB_HCM', 'name' => 'Deutsche Bank — CN HCM', 'short_name' => 'DB HCM', 'pg_name' => 'DBS', 'baca_name' => 'DB HCM'],
            ['code' => 'DBS', 'name' => 'DBS Bank Ltd — CN HCM', 'short_name' => 'DBS', 'pg_name' => 'DBS BANK LTD CN HCM', 'baca_name' => 'DBS'],
            ['code' => 'EIB', 'name' => 'Ngân hàng TMCP Xuất Nhập khẩu Việt Nam', 'short_name' => 'Eximbank', 'pg_name' => 'XUAT NHAP KHAU VIET NAM (EXIMBANK)', 'baca_name' => 'EIB'],
            ['code' => 'ESUN', 'name' => 'E.SUN Commercial Bank (nhãn app Bắc Á)', 'short_name' => 'E.SUN', 'pg_name' => 'ESUN', 'baca_name' => 'ESUN BANK'],
            ['code' => 'FCB_HCM', 'name' => 'First Commercial Bank — CN HCM', 'short_name' => 'FCB HCM', 'pg_name' => 'FCB HCM', 'baca_name' => 'FCB HCM'],
            ['code' => 'FCB_HN', 'name' => 'First Commercial Bank — CN HN', 'short_name' => 'FCB HN', 'pg_name' => 'FCB Ha', 'baca_name' => 'FCB HN'],
            ['code' => 'GPB', 'name' => 'Ngân hàng Thương mại TNHH MTV Dầu Khí Toàn Cầu', 'short_name' => 'GPBank', 'pg_name' => 'KY NGUYEN THINH VUONG (GPBANK)', 'baca_name' => 'GPBANK'],
            ['code' => 'HDB', 'name' => 'Ngân hàng TMCP Phát triển TP.HCM', 'short_name' => 'HDBank', 'pg_name' => 'NH PHAT TRIEN TP HCM (HDBANK)', 'baca_name' => 'HDB'],
            ['code' => 'HLB', 'name' => 'Ngân hàng TNHH MTV Hong Leong Việt Nam', 'short_name' => 'Hong Leong Bank', 'pg_name' => 'hong leong', 'baca_name' => 'HLBVN'],
            ['code' => 'HSBC', 'name' => 'Ngân hàng TNHH MTV HSBC (Việt Nam)', 'short_name' => 'HSBC', 'pg_name' => 'HSBC BANK', 'baca_name' => 'HSBC VN'],
            ['code' => 'HUANAN_HCM', 'name' => 'Hua Nan Commercial Bank — CN HCM', 'short_name' => 'Hua Nan', 'pg_name' => 'hua nan', 'baca_name' => 'HUA NAN HCM'],
            ['code' => 'KBANK', 'name' => 'Ngân hàng Đại chúng TNHH Kasikornbank', 'short_name' => 'KBank', 'pg_name' => 'KBANK', 'baca_name' => 'KBANK'],
            ['code' => 'KLB', 'name' => 'Ngân hàng TMCP Kiên Long', 'short_name' => 'KienLongBank', 'pg_name' => 'KIEN LONG (KIENLONG BANK)', 'baca_name' => 'KLB'],
            ['code' => 'LIO', 'name' => 'Ngân hàng số Liobank', 'short_name' => 'LioBank', 'pg_name' => 'lio', 'baca_name' => 'LIOBANK'],
            ['code' => 'LPB', 'name' => 'Ngân hàng TMCP Lộc Phát Việt Nam', 'short_name' => 'LPBank', 'pg_name' => 'LPBANK', 'baca_name' => 'LPBANK'],
            ['code' => 'MAFC', 'name' => 'Mirae Asset Finance)', 'short_name' => 'MAFC', 'pg_name' => 'MAFC', 'baca_name' => 'MAFC'],
            ['code' => 'MB', 'name' => 'Ngân hàng TMCP Quân đội', 'short_name' => 'MBBank', 'pg_name' => 'MB', 'baca_name' => 'MB'],
            ['code' => 'MBV', 'name' => 'Ngân hàng TNHH MTV Việt Nam Hiện Đại (OceanBank)', 'short_name' => 'Oceanbank', 'pg_name' => 'OceanBank', 'baca_name' => 'MBV'],
            ['code' => 'MSB', 'name' => 'Ngân hàng TMCP Hàng Hải', 'short_name' => 'MSB', 'pg_name' => 'HANG HAI VIET NAM (MARITIME BANK/MSB)', 'baca_name' => 'MSB'],
            ['code' => 'NAB', 'name' => 'Ngân hàng TMCP Nam Á', 'short_name' => 'NamABank', 'pg_name' => 'NAM A (NAM A BANK)', 'baca_name' => 'NAMABANK'],
            ['code' => 'NCB', 'name' => 'Ngân hàng TMCP Quốc Dân', 'short_name' => 'NCB', 'pg_name' => 'NCB', 'baca_name' => 'NCB'],
            ['code' => 'NHB', 'name' => 'Nonghyup Bank — CN Hà Nội', 'short_name' => 'Nonghyup', 'pg_name' => 'Nonghyup', 'baca_name' => 'NONGHYUP'],
            ['code' => 'OCB', 'name' => 'Ngân hàng TMCP Phương Đông', 'short_name' => 'OCB', 'pg_name' => 'OCB', 'baca_name' => 'OCB'],
            ['code' => 'OCBC_HCM', 'name' => 'OCBC — CN HCM', 'short_name' => 'OCBC HCM', 'pg_name' => 'OVERSEA CHINESE BANKING COP LTD', 'baca_name' => 'OCBC HCM'],
            ['code' => 'PGB', 'name' => 'Ngân hàng TMCP Xăng dầu Petrolimex', 'short_name' => 'PGBank', 'pg_name' => 'PGBank', 'baca_name' => 'PGBANK'],
            ['code' => 'PBVN', 'name' => 'Ngân hàng TNHH MTV Public Việt Nam', 'short_name' => 'Public Bank VN', 'pg_name' => 'Public Bank VN', 'baca_name' => 'PUBLIC BANK VN'],
            ['code' => 'PVCB', 'name' => 'Ngân hàng TMCP Đại chúng Việt Nam', 'short_name' => 'PVcomBank', 'pg_name' => 'DAI CHUNG VIET NAM (PVCOMBANK)|PVcomBANK-NPS (PVcomBANK-NPS)|PVcomBank (PVcomBank)', 'baca_name' => 'PVCOMBANK|PVCOMBANK PAY'],
            ['code' => 'SCB', 'name' => 'Ngân hàng TMCP Sài Gòn Thương Tín', 'short_name' => 'Sacombank', 'pg_name' => 'SAI GON THUONG TIN (SACOMBANK)|Sacombank (Sacombank)', 'baca_name' => 'SACOMBANK'],
            ['code' => 'SGCB', 'name' => 'Ngân hàng TMCP Sài Gòn', 'short_name' => 'SCB', 'pg_name' => 'SCB', 'baca_name' => 'SCB'],
            ['code' => 'SGB', 'name' => 'Ngân hàng TMCP Sài Gòn Công Thương', 'short_name' => 'SaigonBank', 'pg_name' => 'SaigonBank', 'baca_name' => 'SAIGONBANK'],
            ['code' => 'SBV', 'name' => 'Ngân hàng Nhà nước Việt Nam (nhãn app)', 'short_name' => 'SBV', 'pg_name' => 'SBV', 'baca_name' => 'SBV'],
            ['code' => 'SCBVN', 'name' => 'Standard Chartered Việt Nam', 'short_name' => 'Standard Chartered VN', 'pg_name' => 'Standard Chartered VN', 'baca_name' => 'SCBVN'],
            ['code' => 'SCSB_DN', 'name' => 'Chinatrust Commercial Bank — CN Đà Nẵng', 'short_name' => 'SCSB DN', 'pg_name' => 'SCSB DN', 'baca_name' => 'SCSB DN'],
            ['code' => 'SEAB', 'name' => 'Ngân hàng TMCP Đông Nam Á', 'short_name' => 'SeABank', 'pg_name' => 'DONG NAM A (SEABANK)', 'baca_name' => 'SEABANK'],
            ['code' => 'CCB_HCM', 'name' => 'China Construction Bank — CN HCM', 'short_name' => 'CCB HCM', 'pg_name' => 'CCB HCM', 'baca_name' => 'CCB HCM'],
            ['code' => 'SHB', 'name' => 'Ngân hàng TMCP Sài Gòn - Hà Nội', 'short_name' => 'SHB', 'pg_name' => 'SHB', 'baca_name' => 'SHB'],
            ['code' => 'SHBVN', 'name' => 'Ngân hàng TNHH MTV Shinhan Việt Nam', 'short_name' => 'ShinhanBank', 'pg_name' => 'SHINHAN BANK', 'baca_name' => 'SHINHAN VN'],
            ['code' => 'SIAM_HCM', 'name' => 'Siam Commercial Bank — CN HCM', 'short_name' => 'Siam Bank', 'pg_name' => 'Siam Bank', 'baca_name' => 'SIAM BANK HCM'],
            ['code' => 'SINOPAC_HCM', 'name' => 'SinoPac Bank — CN HCM', 'short_name' => 'SinoPac', 'pg_name' => 'SinoPac', 'baca_name' => 'SINOPAC HCM'],
            ['code' => 'SMBC_HCM', 'name' => 'Sumitomo Mitsui Banking Corporation — CN HCM', 'short_name' => 'SMBC HCMC', 'pg_name' => 'SMBC HCMC', 'baca_name' => 'SMBC HCMC'],
            ['code' => 'SMBC_HN', 'name' => 'Sumitomo Mitsui Banking Corporation — CN HN', 'short_name' => 'SMBC HN', 'pg_name' => 'SMBC HN', 'baca_name' => 'SMBC HN'],
            ['code' => 'FUBON_BD', 'name' => 'Taipei Fubon Bank — CN Bình Dương', 'short_name' => 'Fubon BD', 'pg_name' => 'TAIPEI FUBON BD', 'baca_name' => 'TAIPEI FUBON BD'],
            ['code' => 'FUBON_HCM', 'name' => 'Taipei Fubon Bank — CN HCM', 'short_name' => 'Fubon HCM', 'pg_name' => 'TAIPEI FUBON HCM', 'baca_name' => 'TAIPEI FUBON HCM'],
            ['code' => 'FUBON_HN', 'name' => 'Taipei Fubon Bank — CN HN', 'short_name' => 'Fubon HN', 'pg_name' => 'TAIPEI FUBON HN', 'baca_name' => 'TAIPEI FUBON HN'],
            ['code' => 'TCB', 'name' => 'Ngân hàng TMCP Kỹ thương Việt Nam', 'short_name' => 'Techcombank', 'pg_name' => 'KY THUONG VIET NAM (TECHCOMBANK/TCB)', 'baca_name' => 'TECHCOMBANK'],
            ['code' => 'TIMO', 'name' => 'Timo by Bản Việt', 'short_name' => 'Timo', 'pg_name' => 'NH so Timo - Don vi truc thuoc Ban Viet (BVBank Timo)', 'baca_name' => 'TIMO'],
            ['code' => 'TPB', 'name' => 'Ngân hàng TMCP Tiên Phong', 'short_name' => 'TPBank', 'pg_name' => 'TIEN PHONG (TPBANK)', 'baca_name' => 'TPBANK'],
            ['code' => 'UB', 'name' => 'Ubank by VPBank', 'short_name' => 'Ubank', 'pg_name' => 'TMCP Viet Nam Thinh Vuong - Ngan hang so Ubank by VPBank (UBANK)', 'baca_name' => 'UBANK'],
            ['code' => 'UMEE', 'name' => 'UMEE by Kienlongbank', 'short_name' => 'UMEE', 'pg_name' => 'NGAN HANG SO UMEE BY KIENLONGBANK (UMEE)', 'baca_name' => 'UMEE'],
            ['code' => 'UOB', 'name' => 'Ngân hàng United Overseas — CN Việt Nam', 'short_name' => 'UOB', 'pg_name' => 'UOB', 'baca_name' => 'UOB'],
            ['code' => 'VBSP', 'name' => 'Ngân hàng Chính sách Xã hội', 'short_name' => 'VBSP', 'pg_name' => 'VBSP', 'baca_name' => 'VBSP'],
            ['code' => 'VCBNEO', 'name' => 'VCB Neo (nhãn app Bắc Á)', 'short_name' => 'VCBNeo', 'pg_name' => 'VCB Neo', 'baca_name' => 'VCBNEO'],
            ['code' => 'VDB', 'name' => 'Ngân hàng Phát triển Việt Nam', 'short_name' => 'VDB', 'pg_name' => 'VDB', 'baca_name' => 'VDB'],
            ['code' => 'VIB', 'name' => 'Ngân hàng TMCP Quốc tế Việt Nam', 'short_name' => 'VIB', 'pg_name' => 'VIB', 'baca_name' => 'VIB'],
            ['code' => 'VAB', 'name' => 'Ngân hàng TMCP Việt Á', 'short_name' => 'VietABank', 'pg_name' => 'VIET A (VIET A BANK)', 'baca_name' => 'VIETABANK'],
            ['code' => 'VB', 'name' => 'Ngân hàng TMCP Việt Nam Thương Tín', 'short_name' => 'VietBank', 'pg_name' => 'VietBank', 'baca_name' => 'VIETBANK'],
            ['code' => 'VCB', 'name' => 'Ngân hàng TMCP Ngoại thương Việt Nam', 'short_name' => 'Vietcombank', 'pg_name' => 'NGOAI THUONG VIET NAM (VIETCOMBANK/VCB)', 'baca_name' => 'VIETCOMBANK'],
            ['code' => 'VTB', 'name' => 'Ngân hàng TMCP Công thương Việt Nam', 'short_name' => 'VietinBank', 'pg_name' => 'CONG THUONG VIET NAM (VIETINBANK)', 'baca_name' => 'VIETINBANK'],
            ['code' => 'VIKKI', 'name' => 'Ngân hàng TNHH MTV Số Vikki', 'short_name' => 'VikkiBank', 'pg_name' => 'VIKKI BY HDBANK', 'baca_name' => 'VIKKI|VIKKI BY HDBANK'],
            ['code' => 'VPB', 'name' => 'Ngân hàng TMCP Việt Nam Thịnh Vượng', 'short_name' => 'VPBank', 'pg_name' => 'VIET NAM THINH VUONG (VPBANK)', 'baca_name' => 'VPBANK'],
            ['code' => 'VRB', 'name' => 'Ngân hàng Liên doanh Việt - Nga', 'short_name' => 'VRB', 'pg_name' => 'VRB', 'baca_name' => 'VRB'],
            ['code' => 'WOO', 'name' => 'Ngân hàng TNHH MTV Woori Việt Nam', 'short_name' => 'Woori', 'pg_name' => 'WOORI VIET NAM (WOORI BANK)', 'baca_name' => 'WOORIBANK'],
            ['code' => 'CBB', 'name' => 'Ngân hàng Thương mại TNHH MTV Xây dựng Việt Nam', 'short_name' => 'CBBank', 'pg_name' => 'XAY DUNG VIET NAM (CBBANK)', 'baca_name' => ''],
        ];

        Bank::truncate();

        foreach ($banks as $row) {
            Bank::create([
                'code' => $row['code'],
                'name' => $row['name'],
                'short_name' => $row['short_name'],
                'pg_name' => $row['pg_name'],
                'baca_name' => $row['baca_name'],
            ]);
        }

        $this->command?->info('Đã seed  bản ghi banks (mảng tĩnh từ PG + Bắc Á).');
    }
}
