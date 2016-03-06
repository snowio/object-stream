<?php
namespace ObjectStream;

interface EventEmitter
{
    public function on(string $event, callable $listener) : self;

    public function once(string $event, callable $listener) : self;

    public function removeListener(string $event, callable $listener) : self;

    public function removeAllListeners(string $event = null) : self;

    public function listeners(string $event) : array;

    public function emit(string $event, array $arguments = []) : self;

    public function eventStream(string $event) : EventStream;
}
