<?php
namespace ObjectStream;

trait ReadableObjectStreamDecorator
{
    /** @var ReadableObjectStream */
    private $readable;

    private function setReadable(ReadableObjectStream $readable)
    {
        $this->readable = $readable;

        foreach (['end', 'readable'] as $readEvent) {
            $readable->on($readEvent, function () use ($readEvent) {
                $this->emit($readEvent, [$this]);
            });
        }

        $readable->on('data', function ($data) {
            $this->emit('data', [$data, $this]);
        });
    }

    public function isPaused(): bool
    {
        return $this->readable->isPaused();
    }

    public function pause()
    {
        $this->readable->pause();
    }

    public function read(int $size = null, bool $allowFewer = true) : array
    {
        return $this->readable->read($size, $allowFewer);
    }

    public function resume()
    {
        $this->readable->resume();
    }
}
