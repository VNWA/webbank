# Logic chuyển tiền tự động (PG Bank & Bắc Á)

Tài liệu bám theo `bot.py`. Tọa độ màn **1080×1920**.  
`keyevent 277` = chọn/xóa nội dung ô trước khi `input text`.  
Chờ quét mặt dài: `run_adb_sleep_batched` (mỗi lần tối đa **8s**, lặp cho đủ `*_FACE_SCAN_WAIT_SEC` trong `.env`).

---

## Tương tác DuoPlus API (POST/GET, payload)

### Base + header

- Base URL: `https://openapi.duoplus.net`
- Header dùng chung:
  - `Content-Type: application/json`
  - `Lang: zh`
  - `DuoPlus-API-Key: <duo_api_token của máy>`
- Trong code hiện tại (`duoplus_post`): **tất cả endpoint đều gọi bằng `POST`**, chưa dùng `GET`.

### Endpoint đang dùng trong bot

| Mục đích | Endpoint | Method | Payload chính |
|---------|----------|--------|---------------|
| Chạy lệnh ADB | `/api/v1/cloudPhone/command` | POST | `{"image_id":"...","command":"input tap ... / monkey ... / sleep ..."}` |
| Xem trạng thái máy | `/api/v1/cloudPhone/status` | POST | `{"image_ids":["<image_id>"]}` |
| Bật máy | `/api/v1/cloudPhone/powerOn` | POST | `{"image_ids":["<image_id>"]}` |
| Tắt máy | `/api/v1/cloudPhone/powerOff` | POST | `{"image_ids":["<image_id>"]}` |
| Lấy info máy (tên hiển thị) | `/api/v1/cloudPhone/info` | POST | `{"image_id":"<image_id>"}` |
| Bật livestream quét mặt | `/api/v1/cloudPhone/live` | POST | `{"image_id":"...","id":"<video_id>","status":1,"loop":1}` |
| Liệt kê file cloud disk | `/api/v1/cloudDisk/list` | POST | body theo hàm `_cloud_disk_list_rows` (lọc file video theo tên/id) |

### Pattern xử lý response trong bot

- Mức API: ưu tiên `payload.code == 200`.
- Mức nghiệp vụ con (`data`):
  - với endpoint command: cần `data.success != false`.
  - với status: lấy `data.list[0].status`.
- Chuẩn hóa helper:
  - `run_adb_command(...)` wrap endpoint `/cloudPhone/command`.
  - `_extract_ui_text(...)` gọi `uiautomator dump` qua `/command`, đọc `data.content`.
  - `get_phone_status(...)` parse `status` int và map ra text.

### Retry / timeout

- `duoplus_post` có retry cho lỗi mạng/TLS và HTTP tạm thời (`408/502/503/504`).
- Config qua env:
  - `DUOPLUS_HTTP_TIMEOUT` (mặc định `120`)
  - `DUOPLUS_MAX_RETRIES` (mặc định `5`)
  - `DUOPLUS_RETRY_BASE_SEC` (mặc định `0.56`)
- Sleep dài trên cloud phone được chia nhỏ bằng `run_adb_sleep_batched(..., chunk_sec=8.0)` để tránh lỗi API khi sleep quá dài.

### Ví dụ payload thực tế (đúng với bot hiện tại)

```json
POST /api/v1/cloudPhone/command
{
  "image_id": "img-123",
  "command": "monkey -p pgbankApp.pgbank.com.vn -c android.intent.category.LAUNCHER 1"
}
```

```json
POST /api/v1/cloudPhone/status
{
  "image_ids": ["img-123"]
}
```

```json
POST /api/v1/cloudPhone/live
{
  "image_id": "img-123",
  "id": "video-file-id",
  "status": 1,
  "loop": 1
}
```

---

## PG Bank

### Check login (`_cmd_checklogin_for_app` + `_run_login_steps`)

- Quyền: `user/admin/super_admin`; bắt buộc chạy trong group và đã chọn máy.
- Luôn đi qua hàng đợi theo `image_id` (`_run_queued`): cùng máy chạy tuần tự, lệnh sau chờ lệnh trước.
- Trước khi login: kiểm tra máy sẵn sàng (`_ensure_ready`) và có `duo_api_token`.
- Chuỗi ADB login chuẩn:
  1. `am force-stop pgbankApp.pgbank.com.vn` (dọn trạng thái cũ).
  2. `sleep 0.35` (scaled theo `_adb_sleep_cmd`).
  3. `monkey -p ... LAUNCHER 1`.
  4. Chờ mở app (`wait_open_sec` của profile PG).
  5. Tap ô password `(232, 939)` → `input text pg_pass`.
  6. Tap Đăng nhập `(340, 1145)`.
  7. Chờ sau login (`wait_after_login_sec`).
  8. Dump UI (`uiautomator dump`).
  9. Nếu lỡ mở Play Store (`com.android.vending`) thì back + force-stop Play Store + mở lại app 1 lần rồi dump lại.
  10. Nếu thấy chữ “Bỏ qua” thì tap `(241, 1834)` → chờ → dump lại.
- Điều kiện pass check login: `_looks_logged_in` của PG (marker home: “xin chào”, “tài khoản”, “chuyển tiền”, ...).
- Kết thúc luôn `force-stop` app ngân hàng.

### Số dư (`_cmd_sodu_for_app`)

- Quyền: `admin/super_admin`; bắt buộc group + đã chọn máy.
- Chạy qua `_run_queued`.
- Trình tự:
  1. Chạy toàn bộ `_run_login_steps` như mục check login.
  2. Tap tab Tài khoản `(539, 1247)` (vì `pgbank.PROFILE.account_tap` có cấu hình) → chờ `sleep 2` (scaled).
  3. Dump UI màn số dư.
  4. Parse bằng `pgbank._extract_balance_snapshot`:
     - Dòng “Tài khoản thanh toán”.
     - STK (chuỗi số dài).
     - Dòng tiền kết thúc `VND`.
  5. Trả kết quả lên Telegram; nếu không parse được thì báo “login OK nhưng chưa trích được số dư”.
- Kết thúc luôn `force-stop` app.

### Ngưỡng & PIN
- **Quét mặt + livestream** khi số tiền ≥ **10.000.000** (`PG_FACE_SCAN_THRESHOLD`).
- **PIN / Smart OTP** (dưới hoặc từ 10tr): **cùng** `pg_pin` — tap ô `(189, 529)` → **≥10tr thêm** `keyevent 277` + `sleep 0.3` (xóa ký tự rác thường có sẵn trên ô Smart OTP) → **mỗi số**: retap ô + `keyevent (7+digit)` (`_pg_pin_adb_command_sequence`). Trước đó ≥10tr: livestream + quét mặt + `PG_FACE_SCAN_WAIT_SEC` (batched) + `sleep 3`.

### Luồng Telegram (tóm tắt)
1. Chọn NH → nhập STK (và có thể số tiền + nội dung nếu `pre_collect`).
2. Xác nhận người nhận → xác nhận số tiền (inline).
3. Bot login PG + chạy ADB theo từng phase dưới.

### Phase A — Prefill (`_runner_pg_prefill`, sau login PG)

| Bước | Hành động | Sleep sau |
|------|-----------|-----------|
| 1 | Tap chuyển tiền `(903, 1241)` | 1.5s |
| 2 | Tap TK khác `(536, 404)` | 1.5s |
| 3 | Đóng popup `(542, 1115)` | 1s |
| 4 | Xóa nội dung CK `(950, 1524)` | 0.3s |
| 5 | Ô nội dung `(245, 1510)` | 0.35s |
| 6 | `input text` nội dung | 0.35s |
| 7 | Tắt phím `(12, 740)` | 0.3s |
| 8 | Ô số tiền `(263, 1293)` | 0.45s |
| 9 | `keyevent 277` + `input text` số tiền | 0.35s |
| 10 | Tap `(12, 740)` | 0.3s |
| 11 | Ô STK `(540, 910)` | 0.45s |
| 12 | `keyevent 277` + `input text` STK | 0.5s |
| 13 | Chọn NH `(540, 1065)` | 1s |
| 14 | Ô search `(220, 562)` + `input text` tên NH | 1.5s |
| 15 | Kết quả đầu `(319, 936)` | **3s** |
| — | Dump UI → Telegram xác nhận | — |

### Phase B — Chỉ STK+NH (không prefill, `_runner_stk_bank`)

| Bước | Hành động | Sleep |
|------|-----------|-------|
| 1 | Ô STK `(540, 910)` | 0.45s |
| 2 | `keyevent 277` + `input text` STK | 1s |
| 3 | Chọn NH `(540, 1065)` | 1s |
| 4 | Search `(220, 562)` + `input text` NH | 1.5s |
| 5 | Dòng đầu `(319, 936)` | 1.5s |
| — | Dump; nếu popup “chuyển thường”: tap `(786, 1157)`, sleep **1s**, dump lại | — |

### Phase C — Sau xác nhận số tiền (`_runner_pg_amount`)

| Bước | Hành động | Sleep |
|------|-----------|-------|
| — | Nếu ≥10tr: bật livestream (API), tin nhắn Telegram | — |
| 1 | Ô số tiền `(263, 1293)` | 0.45s |
| 2 | `keyevent 277` + `input text` amount | 1s |
| 3 | Blur `(12, 740)` | 0.5s |
| 4 | Tiếp tục `(536, 1797)` | **5s** |
| 5 | Nếu UI có “chọn tài khoản/thẻ”: tap `(540, 1730)` | 1s |
| 6 | Lại Tiếp tục `(536, 1797)` | **6s** nếu có face / **5s** không |
| 7 | Nếu lại popup TK nguồn: tap `(540, 1730)` → Tiếp tục → **2s** → dump | — |
| 8 | Nếu form mất NH: chọn lại NH + Kiểm tra + Tiếp tục (1s, 1s, 1.5s, 1s, 2s, 3s) | theo cột |
| 9 | Nếu ≥10tr: tap chân dung `(593, 1741)` → **`PG_FACE_SCAN_WAIT_SEC`** (batched) | theo .env |
| 10 | Dump; nếu vẫn form CK → hủy | — |
| 11 | Nếu có face: **sleep 3s** | 3s |
| 12 | Tap PIN `(189, 529)` | 0.45s |
| 12b | **Chỉ ≥10tr:** `keyevent 277` + **0.3s** (dọn ô OTP) | — |
| 13 | 6 lần: retap `(189,529)` + keyevent từng số | — |
| 14 | Chờ | **2s** |
| 15 | Dump: lỗi PIN / khoá Smart OTP → tap dismiss tương ứng, stop app | — |
| 16 | **4s** → dump “chuyển tiền thành công”; không thấy → **3s** → dump lại | 4s / 3s |

---

## Bắc Á Bank

### Check login (`_cmd_checklogin_for_app` + `_run_login_steps`)

- Quyền: `user/admin/super_admin`; bắt buộc group và đã chọn máy.
- Chạy tuần tự theo hàng đợi máy (`_run_queued`).
- Chuỗi login dùng profile Bắc Á (`bacabank.PROFILE`):
  1. `am force-stop com.bab.retailUAT`.
  2. `sleep 0.35` (scaled).
  3. `monkey -p com.bab.retailUAT ...`.
  4. Chờ mở app (`wait_open_sec=3`).
  5. Tap ô password `(193, 839)` → `input text bac_a_pass`.
  6. Tap Đăng nhập `(301, 1005)`.
  7. Chờ sau login (`wait_after_login_sec=3`).
  8. Dump UI và kiểm tra marker login (`content-desc/text` có “Tài khoản thanh toán”).
- Riêng Bắc Á, sau khi login OK bot còn:
  - Tap icon hiện số dư `(956, 967)` (`reveal_balance_tap`) → chờ 1s (scaled) → dump lại.
  - Parse số dư; nếu phát hiện số dư âm (`_has_negative_balance`) thì cảnh báo “login thành công nhưng số dư bất thường (âm)”.
- Kết thúc luôn `force-stop` app.

### Số dư (`_cmd_sodu_for_app`)

- Quyền: `admin/super_admin`; bắt buộc group + đã chọn máy.
- Chạy qua `_run_queued`.
- Trình tự:
  1. Login như `_run_login_steps`.
  2. Không cần tap tab account riêng (`account_tap=None`).
  3. Tap icon hiện số dư `(956, 967)` (`reveal_balance_tap`) → chờ 1s (scaled).
  4. Dump UI.
  5. Parse bằng `bacabank._extract_balance_snapshot`:
     - “Tài khoản thanh toán” (label),
     - STK (số hoặc dạng mask `1234xxxxx789`),
     - Dòng số dư / giá trị `VND`.
  6. Trả kết quả; nếu không parse được thì báo “login OK nhưng chưa trích được số dư”.
- Kết thúc luôn `force-stop` app.

### Ngưỡng & PIN
- **Quét mặt** khi ≥ **10.000.000** (`BACA_FACE_SCAN_THRESHOLD`): bật livestream, chờ **`BACA_FACE_SCAN_WAIT_SEC`** (batched).
- **PIN/OTP:** `bac_a_pin` — trước **mỗi** số: tap vùng mã `(540, 1180)` (`BACA_OTP_FOCUS_TAP`) + `sleep 0.12`, rồi tap phím numpad (`BACA_NUMPAD_DIGITS`) + `sleep 0.28`. Trước vòng lặp: `sleep 0.6`. Sau đó tap **Xác nhận** `(590, 1761)`, **sleep 3s**, dump.

### Luồng Telegram (tóm tắt)
- Mặc định lệnh CK: `pre_collect` → chọn NH → STK → số tiền → nội dung → xác nhận prefill → chạy prefill ADB → xác nhận người nhận → xác nhận số tiền → `_runner_baca_amount`.

### Phase A — Prefill (`_runner_baca_prefill`, sau login Bắc Á)

| Bước | Hành động | Sleep |
|------|-----------|-------|
| 1 | Chuyển tiền `(148, 1244)` | 1.5s |
| 2 | TK khác `(280, 371)` | 1.5s |
| 3 | Ô STK `(316, 652)` | — |
| 4 | `keyevent 277` + `input text` STK | 1s |
| 5 | Chọn NH `(470, 851)` | 1s |
| 6 | Search `(322, 595)` + `input text` NH | 1.5s |
| 7 | Dòng đầu `(316, 803)` | 1.5s |
| 8 | Kiểm tra `(985, 1121)` | **2s** |
| — | Dump → tên TH → Telegram | — |

### Phase B — Chỉ STK+NH (không prefill, `_runner_stk_bank` Bắc Á)

| Bước | Hành động | Sleep |
|------|-----------|-------|
| 1 | Ô STK `(316, 652)` + `keyevent 277` + STK | 1s |
| 2 | Chọn NH `(470, 851)` | 1s |
| 3 | Search `(322, 595)` | 0.3s |
| 4 | `input text` NH | 1.5s |
| 5 | Dòng đầu `(316, 803)` | 1.5s |
| 6 | Kiểm tra `(985, 1121)` | **2s** |

### Phase C — Sau xác nhận số tiền (`_runner_baca_amount`)

| Bước | Hành động | Sleep |
|------|-----------|-------|
| — | Nếu ≥10tr: livestream + Telegram | — |
| 1 | Tiếp tục 1 `(539, 1782)` | 1s |
| 2 | Ô số tiền `(313, 1103)` | — |
| 3 | `keyevent 277` + `input text` amount | 1s |
| 4 | Blur `(27, 1032)` | 0.5s |
| 5 | Ô nội dung `(795, 1305)` | — |
| 6 | Xóa ND `(936, 1016)` + `input text` note | 0.5s |
| 7 | Blur `(18, 749)` | 0.5s |
| 8 | Tiếp tục 2 `(512, 1815)` | 1s |
| 9 | Lần 2 cùng tọa độ `(512, 1815)` | **1.5s** |
| 10 | Nếu face: `BACA_FACE_SCAN_WAIT_SEC` (batched) | .env |
| 11 | Dump; nếu chưa màn PIN: có thể tap Tiếp tục 2 lại + **1.5s** + dump | — |
| 12 | **0.6s** → nhập 6 số: mỗi số tap focus `(540,1180)` + numpad (xem mục PIN) | — |
| 13 | Chờ | 0.5s |
| 14 | Dump PIN sai? → hủy | — |
| 15 | Xác nhận `(590, 1761)` | **3s** |
| 16 | Dump “thành công”; không thấy → **3s** → dump lại | 3s |

---

## Ghi chú chỉnh sửa

- Đổi tọa độ: sửa `PG_TRANSFER_*` / `BACA_TRANSFER_*`, `BACA_NUMPAD_DIGITS`, `BACA_OTP_FOCUS_TAP` trong `bot.py` (OTP Bắc Á: chỉnh `(540,1180)` theo `uidump/e2e_baca_on_otp_screen*.xml` nếu lệch).
- Đổi thời gian chờ cố định: sửa trực tiếp các `sleep` trong các `_runner_*` tương ứng.
- Chờ quét mặt: `.env` `PG_FACE_SCAN_WAIT_SEC`, `BACA_FACE_SCAN_WAIT_SEC`; chunk nội bộ `run_adb_sleep_batched` mặc định **8s** mỗi lệnh ADB.
