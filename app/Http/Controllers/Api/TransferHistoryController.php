<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransferHistoryResource;
use App\Models\Device;
use App\Models\TransferHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransferHistoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        $perPage = min(max((int) $request->integer('per_page', 15), 5), 100);
        $search = $request->string('search')->trim()->value();
        $channel = $request->string('channel')->trim()->value();

        $query = TransferHistory::query()
            ->with(['device:id,name,image_id', 'requester:id,name'])
            ->orderByDesc('id');

        if ($channel === 'pg' || $channel === 'baca') {
            $query->where('channel', $channel);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('bank_name', 'like', $like)
                    ->orWhere('account_number', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhereHas('device', function ($d) use ($like): void {
                        $d->where('name', 'like', $like)->orWhere('image_id', 'like', $like);
                    })
                    ->orWhereHas('requester', function ($u) use ($like): void {
                        $u->where('name', 'like', $like);
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from')->format('Y-m-d'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to')->format('Y-m-d'));
        }

        return TransferHistoryResource::collection($query->paginate($perPage));
    }
}
