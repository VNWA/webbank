<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationRole;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('Users/Index', [
            'assignableRoles' => $this->assignableRoles($request->user()),
        ]);
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->hasRole(ApplicationRole::SuperAdmin->value)) {
            return ApplicationRole::values();
        }

        return [
            ApplicationRole::Admin->value,
            ApplicationRole::User->value,
        ];
    }
}
