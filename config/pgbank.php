<?php

return [
    'face_scan_threshold' => 10_000_000,

    /*
    | So khớp NH nhận = NH PG (chuyển nội bộ). Chuỗi/name_keywords: không dấu, so khớp chứa.
    */
    'own_bank_match' => [
        'codes' => ['PGBANK', 'PG'],
        'name_keywords' => [
            'PGBANK',
            'PG BANK',
            'PETROLIMEX',
            'XANG DAU',
            'PETROLIMEXBANK',
        ],
    ],

    /** Chuyển khoản nội bộ PG (trong hệ thống) — tọa độ / chờ (chỉnh theo máy). */
    'internal_transfer' => [
        'tap' => [
            'in_system' => [176, 446],
            'close_modal' => [564, 1127],
            // Nội dung: tap xóa → tap ô → `input text` (ADB) → tap blur (tọa độ theo calibrate thiết bị).
            'clear_note' => [948.2, 1345],
            'note' => [900, 1345],
            'blur_corner' => [20, 604],
            'amount' => [276, 1106],
            'account' => [337, 908],
            'continue' => [516, 1782],
            'error_dismiss' => [542, 1151],
        ],
        'timing' => [
            'wait_after_in_system' => 1.0,
            'wait_after_close_modal' => 1.0,
            'wait_after_note_clear_first_tap' => 0.28,
            'wait_after_note_focus_tap' => 0.28,
            'wait_after_field_blur' => 0.35,
            'wait_before_continue' => 1.5,
            'wait_after_continue_dump' => 1.2,
            // Chờ thêm sau khi gửi PIN (0 = giống CK thường).
            'wait_after_otp_extra' => 0,
        ],
        /** false = bỏ tap clear_note nội bộ (màn không có nút X). Mặc định true. */
        'clear_note_tap_enabled' => true,
        /** Tối đa số lần thử (bấm Tiếp tục + kiểm tra dump). */
        'continue_max_attempts' => 4,
        /** Chuỗi nhận diện lỗi tạm thời (dump UI, không phân biệt hoa thường). */
        'temporary_error_needle' => 'giao dịch không thực hiện được trong lúc này',
    ],

    'timing' => [
        'wait_open' => 4.0,
        'wait_after_login' => 3.0,
        'wait_before_skip' => 2.0,
        'wait_after_bank_search' => 1.5,
        'wait_after_select_bank' => 3.0,
        'wait_after_continue' => 5.0,
        'wait_after_otp' => 2.5,
        // Cùng luồng OTP cho mọi CK PG: sau 6 số gửi Enter (66). Dump thực tế vẫn ở màn Smart OTP nếu tắt — app cần Enter để xác nhận.
        'otp_send_enter_after_digits' => true,
        // true = luôn dùng keycode numpad 0–9 (144–153) như khi bật livestream; false = keycode bàn phím 7–16 (mặc định khi < ngưỡng quét mặt).
        'otp_use_numpad_keycodes' => false,
        // Trước khi gõ 6 số từ pg_pin: số vòng chọn-all + xóa (277 → 67), rồi burst backspace — tránh ô PIN còn ký tự/chấm sẵn.
        'otp_clear_select_all_cycles' => 2,
        'otp_field_clear_del_burst' => 12,
        // true = tap lại vùng focus trước mỗi số (hành vi cũ); false = chỉ tap focus lúc đầu (ít gây nhầm với IME).
        'otp_refocus_each_digit' => false,
        // Sau **một** tap `otp_focus`: chờ đủ để ô PIN nhận focus (tránh tap thừa).
        'wait_after_otp_focus' => 0.38,
        // Tap 2 lần cùng tọa độ dễ đè lên bàn phím số PG → tạo 1 số “ảo” trước khi gõ pg_pin — nên để false.
        'otp_double_tap_focus' => false,
        // Nội dung CK PG: chờ sau tap xóa / tap ô nhập (merge internal_transfer.timing nếu override).
        'note_wait_before_note_block' => 0.4,
        'note_clear_tap_repeat' => 2,
        'note_wait_between_clear_taps' => 0.22,
        'note_wait_after_clear_tap' => 0.35,
        'note_wait_after_focus_tap' => 0.28,
        'note_after_input_pause' => 0.15,
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
        // Nội dung liên NH: tap xóa (vùng số tiền/nội dung) → tap ô nội dung → nhập → blur riêng bước nội dung.
        'clear_note' => [948.2, 1540.8],
        'note' => [940, 1540],
        'note_blur' => [20, 604],
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
