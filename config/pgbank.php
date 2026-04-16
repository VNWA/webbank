<?php

return [
    'face_scan_threshold' => 10_000_000,

    'timing' => [
        'wait_open' => 4.0,
        'wait_after_login' => 3.0,
        'wait_before_skip' => 2.0,
        'wait_after_bank_search' => 1.5,
        'wait_after_select_bank' => 3.0,
        'wait_after_continue' => 5.0,
        'wait_after_otp' => 2.0,
        'wait_after_success' => 4.0,
        'wait_after_success_retry' => 3.0,
        'wait_face_scan' => 12.0,

        // Load list bank/search results
        'wait_bank_open' => 1.0,
        'wait_bank_typing' => 1.8,
        'wait_bank_selected' => 3.2,
    ],

    'tap' => [
        'password' => [232, 939],
        'login' => [340, 1145],
        'skip' => [248, 1856],
        'account_balance' => [539, 1247],
        'transfer' => [903, 1241],
        'other_account' => [536, 404],
        'dismiss_popup' => [542, 1115],
        'clear_note' => [950, 1524],
        'note' => [245, 1510],
        'blur' => [12, 740],
        'amount' => [263, 1293],
        'account' => [540, 910],
        'bank' => [540, 1065],
        'bank_search' => [220, 562],
        'bank_first_row' => [319, 936],
        'continue' => [536, 1797],
        'source_account_first' => [540, 1730],
        'face_portrait' => [593, 1741],
        'otp_focus' => [189, 529],
        'pin_wrong_dismiss' => [540, 1158],
        'smart_otp_lock_dismiss' => [294, 1186],
        'normal_confirm' => [786, 1157],
    ],

    // Danh sách ngân hàng trong app PG (lấy từ botBank PGBANK_BANK_LIST).
    // Khi chuyển tiền, tên NH từ banklookup sẽ được fuzzy-match với list này
    // để gõ đúng tên search trong app.
    'bank_list' => [
        'A CHAU (ACB)',
        'ANZ BANK',
        'BAC A (BAC A -HSC)',
        'BAN VIET (BVBank)',
        'BANGKOK BANK HCM',
        'CIMB BANK (CIMB)',
        'CONG THUONG VIET NAM (VIETINBANK)',
        'Cathay United Bank CN HCM (CUBHCM)',
        'DAI CHUNG VIET NAM (PVCOMBANK)',
        'DAU TU VA PHAT TRIEN VIET NAM (BIDV)',
        'DBS BANK LTD CN HCM',
        'DONG NAM A (SEABANK)',
        'HANG HAI VIET NAM (MARITIME BANK/MSB)',
        'HSBC BANK',
        'INDOVINA BANK (IVB)',
        'KIEN LONG (KIENLONG BANK)',
        'KY NGUYEN THINH VUONG (GPBANK)',
        'KY THUONG VIET NAM (TECHCOMBANK/TCB)',
        'NAM A (NAM A BANK)',
        'NGAN HANG SO UMEE BY KIENLONGBANK (UMEE)',
        'NGOAI THUONG VIET NAM (VIETCOMBANK/VCB)',
        'NH PHAT TRIEN TP HCM (HDBANK)',
        'NH so Timo - Don vi truc thuoc Ban Viet (BVBank Timo)',
        'NONG NGHIEP VA PHAT TRIEN NONG THON VIET NAM (AGRIBANK)',
        'Ngan hang Bank of China - CN.TPHCM (BOCHK)',
        'OVERSEA CHINESE BANKING COP LTD',
        'PVcomBANK-NPS (PVcomBANK-NPS)',
        'PVcomBank (PVcomBank)',
        'SAI GON THUONG TIN (SACOMBANK)',
        'SHINHAN BANK',
        'Sacombank (Sacombank)',
        'TIEN PHONG (TPBANK)',
        'TMCP Viet Nam Thinh Vuong - Ngan hang so CAKE by VPBank (CAKE)',
        'TMCP Viet Nam Thinh Vuong - Ngan hang so Ubank by VPBank (UBANK)',
        'VIET A (VIET A BANK)',
        'VIET NAM THINH VUONG (VPBANK)',
        'VIKKI BY HDBANK',
        'WOORI VIET NAM (WOORI BANK)',
        'XAY DUNG VIET NAM (CBBANK)',
        'XUAT NHAP KHAU VIET NAM (EXIMBANK)',
    ],

    // Map bổ sung: banklookup short_name (UPPER, bỏ ký tự đặc biệt) -> tên trong app PG.
    // Chỉ cần khi fuzzy-match không tìm ra đúng.
    'bank_name_map' => [
        // 'VIETCOMBANK' => 'NGOAI THUONG VIET NAM (VIETCOMBANK/VCB)',
    ],
];

