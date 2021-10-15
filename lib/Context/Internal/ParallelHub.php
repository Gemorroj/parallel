<?php

namespace Amp\Parallel\Context\Internal;

use Amp\Parallel\Sync\ChannelledSocket;
use parallel\Events;
use parallel\Future;
use Revolt\EventLoop;

class ParallelHub extends ProcessHub
{
    private const EXIT_CHECK_FREQUENCY = 0.25;

    /** @var ChannelledSocket[] */
    private array $channels = [];

    private string $watcher;

    private Events $events;

    public function __construct()
    {
        parent::__construct();

        $events = $this->events = new Events;
        $this->events->setBlocking(false);

        $channels = &$this->channels;
        $this->watcher = EventLoop::repeat(self::EXIT_CHECK_FREQUENCY, static function () use (&$channels, $events): void {
            while ($event = $events->poll()) {
                $id = (int) $event->source;
                \assert(isset($channels[$id]), 'Channel for context ID not found');
                $channel = $channels[$id];
                unset($channels[$id]);
                $channel->close();
            }
        });
        EventLoop::disable($this->watcher);
        EventLoop::unreference($this->watcher);
    }

    public function add(int $id, ChannelledSocket $channel, Future $future): void
    {
        $this->channels[$id] = $channel;
        $this->events->addFuture((string) $id, $future);

        EventLoop::enable($this->watcher);
    }

    public function remove(int $id): void
    {
        if (!isset($this->channels[$id])) {
            return;
        }

        unset($this->channels[$id]);
        $this->events->remove((string) $id);

        if (empty($this->channels)) {
            EventLoop::disable($this->watcher);
        }
    }
}
