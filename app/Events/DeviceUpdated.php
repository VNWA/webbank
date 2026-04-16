<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $device  Payload từ ManagedDeviceResource::resolve()
     */
    public function __construct(public array $device) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.updated';
    }

    /**
     * @return array{device: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'device' => $this->device,
        ];
    }
}
