<?php

namespace App\Http\Requests;

use App\Enums\ApplicationRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowedRoles = $this->allowedRoleValues();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in($allowedRoles)],
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedRoleValues(): array
    {
        if ($this->user()->hasRole(ApplicationRole::SuperAdmin->value)) {
            return ApplicationRole::values();
        }

        return [
            ApplicationRole::Admin->value,
            ApplicationRole::User->value,
        ];
    }
}
