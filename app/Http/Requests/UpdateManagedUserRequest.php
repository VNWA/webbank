<?php

namespace App\Http\Requests;

use App\Enums\ApplicationRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $managed */
        $managed = $this->route('user');

        return $this->user()->can('update', $managed);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $managed */
        $managed = $this->route('user');
        $allowedRoles = $this->allowedRoleValues();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($managed->id),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
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
