<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSavedTransferRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');

        return $device instanceof Device
            && $this->user() !== null
            && $this->user()->can('update', $device);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'account_number' => ['required', 'string', 'max:64'],
            'recipient_name' => ['required', 'string', 'max:255'],
        ];
    }
}
