<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $requestId;
    public $progress;

    public function __construct($progress, $requestId)
    {
        $this->progress = $progress;
        $this->requestId = $requestId;
    }

    public function broadcastOn()
    {
        Log::info('Broadcasting on progress-channel');
        return new Channel('progress-channel');
    }

    public function failed(\Exception $exception)
    {
        Log::error('Failed to broadcast event: ' . $exception->getMessage());
    }

    public function broadcastAs()
    {
        return 'ProgressUpdated';
    }
}
