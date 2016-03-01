<?php
namespace ObjectStream;

trait ReadableObjectStreamDecorator
{
    /** @var ReadableObjectStream */
    private $readable;

    private function setReadable(ReadableObjectStream $readable)
    {
        $this->readable = $readable;
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

    public function pipe(WritableObjectStream $destination, array $options = [])
    {
        return $this->readable->pipe($destination, $options);
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

    public function unpipe(WritableObjectStream $destination = null)
    {
        $this->readable->unpipe($destination);
    }
}
