<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class UpdateManagedDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Device $device */
        $device = $this->route('device');

        return $this->user()->can('update', $device);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pg_pass' => ['required', 'string', 'max:255'],
            'pg_pin' => ['required', 'string', 'max:255'],
            'baca_pass' => ['required', 'string', 'max:255'],
            'baca_pin' => ['required', 'string', 'max:255'],
            'pg_video_id' => ['required', 'string', 'max:255'],
            'baca_video_id' => ['required', 'string', 'max:255'],
        ];
    }
}
