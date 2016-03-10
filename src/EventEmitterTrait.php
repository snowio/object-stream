<?php
namespace ObjectStream;

trait EventEmitterTrait
{
    use \Evenement\EventEmitterTrait {
        emit as private _emit;
        listeners as private _listeners;
        on as private _on;
        once as private _once;
        removeListener as private _removeListener;
        removeAllListeners as private _removeAllListeners;
    }

    private $persistentEvents = [];
    private $eventStreams = [];

    protected function registerPersistentEvents(string ...$events)
    {
        foreach ($events as $event) {
            if (!array_key_exists($event, $this->persistentEvents)) {
                $this->persistentEvents[$event] = null;
            }
        }
    }

    public function emit(string $event, array $arguments = []) : EventEmitter
    {
        if (array_key_exists($event, $this->persistentEvents)) {
            $this->persistentEvents[$event] = $arguments;
        }

        $this->_emit($event, $arguments);

        return $this;
    }

    public function eventStream(string $event) : EventStream
    {
        return $this->eventStreams[$event] ?? ($this->eventStreams[$event] = new EventStream($this, $event));
    }

    public function listeners(string $event) : array
    {
        return $this->_listeners($event);
    }

    public function on(string $event, callable $listener) : EventEmitter
    {
        if (isset($this->persistentEvents[$event])) {
            call_user_func_array($listener, $this->persistentEvents[$event]);
        }

        $this->_on($event, $listener);
        $this->emit('__listenersChanged');

        return $this;
    }

    public function once(string $event, callable $listener) : EventEmitter
    {
        if (isset($this->persistentEvents[$event])) {
            call_user_func_array($listener, $this->persistentEvents[$event]);
        } else {
            $this->_once($event, $listener);
            $this->emit('__listenersChanged');
        }

        return $this;
    }

    public function removeListener(string $event, callable $listener) : EventEmitter
    {
        $this->_removeListener($event, $listener);
        $this->emit('__listenersChanged');

        return $this;
    }

    public function removeAllListeners(string $event = null) : EventEmitter
    {
        $this->_removeAllListeners($event);
        $this->emit('__listenersChanged');

        return $this;
    }
}
