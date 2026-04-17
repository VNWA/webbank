<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\DuoPlusApi;
use App\Support\BalanceFromUiDumpParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DumpDeviceUiCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'device:dump-ui
                            {device : ID thiết bị (devices.id)}
                            {--save= : Ghi raw dump vào file (đường dẫn trong storage/app hoặc tuyệt đối)}';

    /**
     * @var string
     */
    protected $description = 'Dump UI (uidump.xml) qua DuoPlus API và in BalanceFromUiDumpParser::parse. Mở đúng màn số dư (PG hoặc Bắc Á), rồi chạy; có thể --save=balance-dumps/device-X-pg.xml để giữ mẫu đối chiếu định dạng.';

    public function handle(DuoPlusApi $duoPlusApi): int
    {
        $id = (int) $this->argument('device');
        $device = Device::query()->find($id);
        if ($device === null) {
            $this->error("Không tìm thấy device id {$id}.");

            return self::FAILURE;
        }

        $command = 'uiautomator dump /sdcard/uidump.xml && cat /sdcard/uidump.xml';
        $this->info("DuoPlus command (image_id={$device->image_id}) …");

        $result = $duoPlusApi->command($device->duo_api_key, $device->image_id, $command);
        if (! $result['ok']) {
            $this->error($result['message'] !== '' ? $result['message'] : 'DuoPlus command thất bại.');

            return self::FAILURE;
        }

        $raw = $this->extractStdoutFromDuoPlusPayload($result['data']);
        $len = mb_strlen($raw);
        $this->info("Nhận {$len} ký tự.");

        $save = $this->option('save');
        if ($save !== null && $save !== '') {
            $path = is_string($save) ? $save : '';
            if ($path !== '' && ! str_starts_with($path, '/')) {
                $path = storage_path('app/'.$path);
            }
            if ($path !== '') {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $raw);
                $this->info("Đã ghi: {$path}");
            }
        }

        $parsed = BalanceFromUiDumpParser::parse($raw);
        if ($parsed === null) {
            $this->warn('BalanceFromUiDumpParser::parse → null (không đọc được số dư từ dump).');
        } else {
            $this->info('BalanceFromUiDumpParser::parse → '.number_format($parsed, 0, ',', '.').' VND');
        }

        $preview = mb_substr($raw, 0, 2500);
        $this->line('--- preview (2500 ký tự đầu) ---');
        $this->line($preview);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $jsonRoot
     */
    private function extractStdoutFromDuoPlusPayload(array $jsonRoot): string
    {
        foreach (['data.content', 'data.data.content', 'data.data.data.content'] as $path) {
            $raw = (string) data_get($jsonRoot, $path, '');
            if ($raw !== '') {
                return $raw;
            }
        }

        return '';
    }
}
