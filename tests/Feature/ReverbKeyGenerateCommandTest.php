<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReverbKeyGenerateCommandTest extends TestCase
{
    public function test_reverb_key_generate_exits_successfully(): void
    {
        $exit = Artisan::call('reverb:key-generate');

        $this->assertSame(0, $exit);
    }
}
