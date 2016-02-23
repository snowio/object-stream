<?php
namespace ObjectStream;

trait ReadableObjectStreamDecorator
{
    /** @var ReadableObjectStream */
    private $readable;

    private function setReadable(ReadableObjectStream $readable)
    {
        $this->readable = $readable;

        foreach (['data', 'end', 'error', 'readable'] as $eventName) {
            $readable->on($eventName, function (...$args) use ($eventName) {
                $this->emit($eventName, $args);
            });
        }
    }

    public function isPaused(): bool
    {
        return $this->readable->isPaused();
    }

    public function pause() : ReadableObjectStream
    {
        $this->readable->pause();
        return $this;
    }

    public function read(int $size = null, bool $allowFewer = true) : array
    {
        return $this->readable->read($size, $allowFewer);
    }

    public function resume() : ReadableObjectStream
    {
        $this->readable->resume();
        return $this;
    }
}
