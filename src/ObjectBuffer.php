<?php
namespace ObjectStream;

use Evenement\EventEmitterTrait;

class ObjectBuffer implements DuplexObjectStream
{
    use EventEmitterTrait;
    use WritableObjectStreamTrait;
    use ReadableObjectStreamTrait;

    private $writeBuffer;
    private $readBuffer;

    public function __construct(int $highWaterMark)
    {
        $this->highWaterMark = $highWaterMark;
        $this->writeBuffer = new \SplQueue();
        $this->readBuffer = new \SplQueue();
        $this->initWritable();
        $this->initReadable();
    }

    /** @see WritableObjectStream */

    public function uncork()
    {
        if (!$this->corked) {
            return;
        }

        $readBufferWasEmpty = $this->readBuffer->isEmpty();
        while (!$this->writeBuffer->isEmpty()) {
            $this->readBuffer->enqueue($this->writeBuffer->dequeue());
        }
        $this->corked = false;

        if (!$this->paused) {
            $this->flow();
        } elseif ($readBufferWasEmpty && !$this->readBuffer->isEmpty()) {
            $this->emit('readable', [$this]);
        }
    }

    protected function _write($object, callable $onFlush)
    {
        if ($this->corked) {
            $this->writeBuffer->enqueue([$object, $onFlush]);
            return;
        }

        assert($this->writeBuffer->isEmpty());

        if ($this->paused) {
            $readBufferWasEmpty = $this->readBuffer->isEmpty();
            $this->readBuffer->enqueue([$object, $onFlush]);
            if ($readBufferWasEmpty) {
                $this->emit('readable', [$this]);
            }
            return;
        }

        assert($this->readBuffer->isEmpty());

        $this->emitData($object, $onFlush);
    }

    /** @see ReadableObjectStream */

    public function read(int $size = null, bool $allowFewer = true) : array
    {
        if ($size < 0) {
            throw new \InvalidArgumentException('Size must be null or a non-negative integer.');
        }

        if ($this->readBuffer->isEmpty()) {
            return [];
        }
        if (0 === $size) {
            return [];
        }
        if ($size > $this->readBuffer->count() && !$allowFewer) {
            return [];
        }

        $objects = [];

        foreach ($this->dequeueFromReadBuffer($size) as list($object, $onFlush)) {
            $this->emitData($object, $onFlush);
            $objects[] = $object;
        }

        return $objects;
    }

    public function resume()
    {
        if ($this->paused) {
            $this->paused = false;
            $this->flow();
        }
    }

    /** @internal */

    private function flow()
    {
        do {
            $objects = $this->read(1);
        } while ([] !== $objects && !$this->paused);
    }

    private function emitData($object, callable $onFlush = null)
    {
        $this->emit('data', [$object, $this]);

        if (null !== $onFlush) {
            call_user_func($onFlush, $object);
        }
    }

    private function dequeueFromReadBuffer(int $size = null) : array
    {
        $tuples = [];

        for ($i = 1; $i <= $size ?: PHP_INT_MAX, !$this->readBuffer->isEmpty(); $i++) {
            $tuples[] = $this->readBuffer->dequeue();
        }

        return $tuples;
    }
}