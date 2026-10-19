<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Tells every open screen that a pool changed (DD7, FR11).
 *
 * Public channel `pool.{id}`, event name `pool.updated`. There is no authentication in this build, so the channel is
 * deliberately not private. It is sent at once, not queued: the caller defers it until after the commit and the
 * response, so it never delays a request.
 */
class PoolUpdated implements ShouldBroadcastNow
{
    /**
     * Primitives only, so the event is cheap to build once the transaction has committed.
     *
     * @param  array<string, mixed>  $entry  The new log entry, already rendered by LogEntryResource.
     */
    public function __construct(
        public int $poolId,
        public int $version,
        public int $remaining,
        public array $entry,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel("pool.{$this->poolId}")];
    }

    public function broadcastAs(): string
    {
        return 'pool.updated';
    }

    /**
     * @return array{version: int, remaining: int, entry: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'version' => $this->version,
            'remaining' => $this->remaining,
            'entry' => $this->entry,
        ];
    }
}
