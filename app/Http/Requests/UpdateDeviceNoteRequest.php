<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceNoteRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
