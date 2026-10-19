<?php

use App\Events\PoolUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

function poolUpdated(): PoolUpdated
{
    return new PoolUpdated(
        poolId: 1,
        version: 12,
        remaining: 88,
        entry: ['seq' => 12, 'kind' => 'allocation', 'granted' => 3],
    );
}

it('broadcasts at once on the public pool channel, never on a private one', function () {
    $channels = poolUpdated()->broadcastOn();

    expect(poolUpdated())->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0])->not->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('pool.1');
});

it('names the event pool.updated and sends version, remaining and the entry', function () {
    $event = poolUpdated();

    expect($event->broadcastAs())->toBe('pool.updated')
        ->and($event->broadcastWith())->toBe([
            'version' => 12,
            'remaining' => 88,
            'entry' => ['seq' => 12, 'kind' => 'allocation', 'granted' => 3],
        ]);
});

it('uses the pool id in the channel name', function () {
    $event = new PoolUpdated(poolId: 7, version: 1, remaining: 1, entry: []);

    expect($event->broadcastOn()[0]->name)->toBe('pool.7');
});
