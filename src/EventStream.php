<?php
namespace ObjectStream;

class EventStream
{
    private $eventEmitter;
    private $event;

    public function __construct(EventEmitter $eventEmitter, string $event)
    {
        $this->eventEmitter = $eventEmitter;
        $this->event = $event;
    }

    public function on(callable $listener) : self
    {
        $this->eventEmitter->on($this->event, $listener);
        return $this;
    }

    public function once(callable $listener) : self
    {
        $this->eventEmitter->once($this->event, $listener);
        return $this;
    }

    public function removeListener(callable $listener) : self
    {
        $this->eventEmitter->removeListener($this->event, $listener);
        return $this;
    }

    public function removeAllListeners() : self
    {
        $this->eventEmitter->removeAllListeners($this->event);
        return $this;
    }

    public function listeners() : array
    {
        return $this->eventEmitter->listeners($this->event);
    }

    public function emit(array $arguments = []) : self
    {
        $this->eventEmitter->emit($this->event, $arguments);
        return $this;
    }
}
