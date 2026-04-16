<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceOperationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $device = $this->route('device');

        return $device instanceof Device
            && $this->user() !== null
            && $this->user()->can('update', $device);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_type' => ['required', 'string', 'in:pg_check_login,baca_check_login'],
        ];
    }
}
