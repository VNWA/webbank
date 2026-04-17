<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ReverbKeyGenerateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'reverb:key-generate';

    /**
     * @var string
     */
    protected $description = 'Sinh REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET trong .env khi các biến này đang trống (tương ứng .env.example khoảng dòng 67–69).';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! File::isFile($path)) {
            $this->error("Không tìm thấy file .env tại: {$path}");

            return self::FAILURE;
        }

        $content = File::get($path);

        $generators = [
            'REVERB_APP_ID' => fn (): string => (string) Str::uuid(),
            'REVERB_APP_KEY' => fn (): string => Str::lower(Str::random(32)),
            'REVERB_APP_SECRET' => fn (): string => Str::lower(Str::random(32)),
        ];

        $updated = $content;
        $changed = false;

        foreach ($generators as $name => $generator) {
            $current = $this->readEnvValue($updated, $name);

            if (! $this->isBlank($current)) {
                continue;
            }

            $value = $generator();
            $updated = $this->writeEnvValue($updated, $name, $value);
            $changed = true;
            $this->line("  <fg=green>{$name}</> = <comment>{$value}</comment>");
        }

        if (! $changed) {
            $this->components->info('REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET đã có giá trị — không ghi đè.');

            return self::SUCCESS;
        }

        File::put($path, $updated);
        $this->newLine();
        $this->components->info('Đã cập nhật .env với credential Reverb (chỉ các biến trước đó trống).');

        return self::SUCCESS;
    }

    private function readEnvValue(string $content, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $content, $matches)) {
            return null;
        }

        $raw = trim($matches[1]);
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return substr($raw, 1, -1);
        }

        if (str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Ghi hoặc thay dòng KEY=value (không thêm dấu ngoặc nếu giá trị an toàn).
     */
    private function writeEnvValue(string $content, string $key, string $value): string
    {
        $escaped = $value;
        if (preg_match('/[\s#\'"]/', $value)) {
            $escaped = '"'.addcslashes($value, "\\\"\n\r\t").'"';
        }

        $pattern = '/^'.preg_quote($key, '/').'=.*/m';

        if (preg_match($pattern, $content)) {
            $replaced = preg_replace($pattern, $key.'='.$escaped, $content);

            return $replaced ?? $content;
        }

        return rtrim($content)."\n{$key}={$escaped}\n";
    }
}
