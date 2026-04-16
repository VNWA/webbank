<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManagedUserRequest;
use App\Http\Requests\UpdateManagedUserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ManagedUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $perPage = min(max((int) $request->integer('per_page', 10), 5), 100);
        $search = $request->string('search')->trim()->value();

        $query = User::query()->orderByDesc('id');

        if ($request->user()->hasRole(ApplicationRole::Admin->value)
            && ! $request->user()->hasRole(ApplicationRole::SuperAdmin->value)) {
            $query->whereDoesntHave('roles', function ($q): void {
                $q->where('name', ApplicationRole::SuperAdmin->value);
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        return ManagedUserResource::collection($query->paginate($perPage));
    }

    public function store(StoreManagedUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $user->syncRoles([$data['role']]);

        return ManagedUserResource::make($user)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateManagedUserRequest $request, User $user): ManagedUserResource
    {
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();
        $user->syncRoles([$data['role']]);

        return ManagedUserResource::make($user->fresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json([], 204);
    }
}
