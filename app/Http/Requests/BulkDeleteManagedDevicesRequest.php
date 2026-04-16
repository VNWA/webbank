<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteManagedDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deleteAny', Device::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:devices,id'],
        ];
    }
}
