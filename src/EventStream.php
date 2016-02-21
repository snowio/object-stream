<?php
namespace ObjectStream;

use Evenement\EventEmitterInterface;

class EventStream
{
    private $eventEmitter;
    private $event;

    public function __construct(EventEmitterInterface $eventEmitter, string $event)
    {
        $this->eventEmitter = $eventEmitter;
        $this->event = $event;
    }

    public function on(callable $listener)
    {
        $this->eventEmitter->on($this->event, $listener);
    }

    public function once(callable $listener)
    {
        $this->eventEmitter->once($this->event, $listener);
    }

    public function removeListener(callable $listener)
    {
        $this->eventEmitter->removeListener($this->event, $listener);
    }

    public function removeAllListeners()
    {
        $this->eventEmitter->removeAllListeners($this->event);
    }

    public function listeners()
    {
        return $this->eventEmitter->listeners($this->event);
    }

    public function emit(array $arguments = [])
    {
        $this->eventEmitter->emit($this->event, $arguments);
    }
}
