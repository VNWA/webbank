<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManagedDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Device::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('image_id')) {
            $this->merge([
                'image_id' => trim((string) $this->input('image_id')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'duo_api_key' => ['required', 'string', 'max:255'],
            'image_id' => ['required', 'string', 'max:255', Rule::unique('devices', 'image_id')],
            'pg_pass' => ['required', 'string', 'max:255'],
            'pg_pin' => ['required', 'string', 'max:255'],
            'baca_pass' => ['required', 'string', 'max:255'],
            'baca_pin' => ['required', 'string', 'max:255'],
            'pg_video_id' => ['required', 'string', 'max:255'],
            'baca_video_id' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['normal', 'pending'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image_id.unique' => 'Mã image_id (cloud phone) đã được gán cho thiết bị khác.',
        ];
    }
}
