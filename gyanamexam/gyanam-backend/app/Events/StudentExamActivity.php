<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a student starts or updates their exam session.
 * Uses ShouldBroadcastNow to send immediately (no queue needed).
 */
class StudentExamActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $session;
    public string $type; // 'started' | 'heartbeat' | 'submitted'

    public function __construct(array $session, string $type = 'heartbeat')
    {
        $this->session = $session;
        $this->type    = $type;
    }

    /**
     * Channel: admin-only public channel (no auth needed for admin portal).
     */
    public function broadcastOn(): Channel
    {
        return new Channel('live-monitoring');
    }

    /**
     * Custom event name on the frontend.
     */
    public function broadcastAs(): string
    {
        return 'student.activity';
    }

    /**
     * Data sent to the frontend.
     */
    public function broadcastWith(): array
    {
        return [
            'type'    => $this->type,
            'session' => $this->session,
        ];
    }
}
