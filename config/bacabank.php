<?php

return [
    'face_scan_threshold' => 10_000_000,

    'flow' => [
        // Nếu true: bấm nút "Kiểm tra" thụ hưởng sau khi chọn bank.
        // Nếu false: bỏ qua "Kiểm tra", chỉ chờ rồi tiếp tục.
        'use_check_beneficiary' => false,
    ],

    'timing' => [
        'wait_open' => 3.0,
        'wait_after_login' => 3.0,
        'wait_after_bank_selected' => 2.0,
        'wait_after_check' => 2.0,
        'wait_after_continue_1' => 1.0,
        'wait_after_amount' => 1.0,
        'wait_after_blur' => 1.5,
        'wait_after_continue_2' => 1.5,
        'wait_before_otp' => 0.6,
        'wait_otp_focus' => 0.12,
        'wait_otp_digit' => 0.28,
        'wait_after_otp_digits' => 0.5,
        'wait_after_confirm' => 3.0,
        'wait_after_success_retry' => 3.0,
        'wait_face_scan' => 5.0,
        // Sau khi chờ quét mặt (livestream), thêm delay trước khi nhập OTP — app cần thời gian chuyển màn.
        'wait_after_face_scan_before_otp' => 1.0,
        // Sau khi tap mở che, chờ UI cập nhật số (giảm nhẹ nếu máy nhanh).
        'wait_reveal_balance' => 1.8,
        // Sau khi vào home, chờ UI ổn rồi mới tap nút mắt.
        'wait_before_reveal_balance' => 1.5,
        // Giữa 2 tap lặp (double tap) trên cùng nút mắt.
        'wait_between_reveal_double_tap' => 0.22,
        // Giữa các lần thử lại (lần 1 lỗi → lần 2…).
        'wait_between_reveal_retries' => 0.9,

        // Load list bank/search results
        'wait_bank_open' => 1.0,
        'wait_bank_search_focus' => 0.3,
        'wait_bank_typing' => 1.8,
        'wait_bank_selected' => 1.8,
    ],

    'tap' => [
        'password' => [193, 839],
        'login' => [301, 1005],
        'transfer' => [148, 1244],
        'other_account' => [280, 371],
        'account' => [316, 652],
        'bank' => [470, 851],
        'bank_search' => [322, 595],
        'bank_first_row' => [316, 803],
        // Bạn chốt flow: sau khi chọn bank+stk xong thì tap vùng này trước rồi mới bấm tiếp tục.
        'after_bank' => [22, 610],
        'check_beneficiary' => [985, 1121],
        // Bạn chốt tọa độ tiếp tục bước 1.
        'continue_step_1' => [558, 1785],
        'amount' => [313, 1103],
        'blur_amount' => [27, 1032],
        'note' => [795, 1305],
        'clear_note' => [936, 1016],
        'blur_note' => [18, 749],
        'continue_step_2' => [512, 1815],
        'otp_focus' => [540, 1180],
        'confirm' => [590, 1761],
        // Mở che số dư (nút mắt). Calibrate ~961.7×999.7 → làm tròn 962×1000 (có thể ghi float trong config).
        'reveal_balance' => [962, 1000],
        // Lần thử 3: tọa độ cũ (fallback) nếu bản app lệch layout.
        'reveal_balance_alt' => [954, 966],
    ],

    // Danh sách ngân hàng trong app Bắc Á (lấy từ botBank BACA_BANK_LIST).
    'bank_list' => [
        'ABBANK', 'ABC HN', 'ACB', 'AGRIBANK', 'ANZ VN',
        'BANGKOK BANK HCM', 'BANGKOK BANK HN', 'BAOVIETBANK', 'BIDC', 'BIDV',
        'BNP PARIBAS HCM', 'BNP PARIBAS HN', 'BOCHK', 'BPCE IOM HCM', 'BUSAN HCM',
        'BVBANK', 'CAKE', 'CCB HCM', 'CIMB BANK', 'CITI BANK HCM',
        'CITI BANK HN', 'CITIBANK VIETNAM', 'COOPBANK', 'CTBC HCM', 'CUBHCM',
        'DAEGU HCM', 'DB HCM', 'DBS', 'EIB', 'ESUN BANK',
        'FCB HCM', 'FCB HN', 'GPBANK', 'HDB', 'HLBVN',
        'HSBC VN', 'HUA NAN HCM', 'IBK HCM', 'IBK HN', 'ICBC HN',
        'IVB', 'JPMORGAN HCM', 'KB HCM', 'KB HN', 'KBANK',
        'KEB HANA HCM', 'KEB HANA HN', 'KLB', 'LIOBANK', 'LPBANK',
        'MAFC', 'MAY BANK HCM', 'MAY BANK HN', 'MB', 'MBV',
        'MEGA ICBC HCM', 'MIZUHO HCM', 'MIZUHO HN', 'MSB', 'NAMABANK',
        'NCB', 'NONGHYUP', 'OCB', 'OCBC HCM', 'PGBANK',
        'PUBLIC BANK VN', 'PVCOMBANK', 'PVCOMBANK PAY', 'SACOMBANK', 'SAIGONBANK',
        'SBV', 'SCB', 'SCBVN', 'SCSB DN', 'SEABANK',
        'SHB', 'SHINHAN VN', 'SIAM BANK HCM', 'SINOPAC HCM', 'SMBC HCMC',
        'SMBC HN', 'TAIPEI FUBON BD', 'TAIPEI FUBON HCM', 'TAIPEI FUBON HN', 'TECHCOMBANK',
        'TIMO', 'TPBANK', 'UBANK', 'UMEE', 'UOB',
        'VBSP', 'VCBNEO', 'VDB', 'VIB', 'VIETABANK',
        'VIETBANK', 'VIETCOMBANK', 'VIETINBANK', 'VIKKI', 'VIKKI BY HDBANK',
        'VPBANK', 'VRB', 'WOORIBANK',
    ],

    // Map bổ sung: banklookup short_name (UPPER, bỏ ký tự đặc biệt) -> tên trong app Bắc Á.
    'bank_name_map' => [
        // 'VIETCOMBANK' => 'VIETCOMBANK',
    ],

    // numpad digits (x,y) for 0-9 on Bac A OTP screen (1080x1920)
    'numpad_digits' => [
        // Khớp botBank/bot.py (BACA_NUMPAD_DIGITS) để tránh lệch digit.
        '0' => [540, 1761],
        '1' => [203, 1381],
        '2' => [540, 1381],
        '3' => [877, 1381],
        '4' => [203, 1507],
        '5' => [540, 1507],
        '6' => [877, 1507],
        '7' => [203, 1634],
        '8' => [540, 1634],
        '9' => [877, 1634],
    ],
];
