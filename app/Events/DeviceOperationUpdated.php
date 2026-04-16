<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceOperationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $operation
     */
    public function __construct(public array $operation) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('device-operations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device-operation.updated';
    }

    /**
     * @return array{operation: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'operation' => $this->operation,
        ];
    }
}
