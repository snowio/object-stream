<?php
namespace ObjectStream;

trait ReadableObjectStreamTrait
{
    private $pipeDestroyers = [];
    private $paused = false;
    /** @var callable */
    private $pushFn;
    /** @var \SplQueue */
    private $readBuffer;
    private $pendingItemLimit = 1;
    private $readEnded = false;
    private $readEndEmitted = false;

    protected function _read(int $size, callable $pushFn)
    {

    }

    public function isPaused() : bool
    {
        return $this->paused;
    }

    public function pause() : ReadableObjectStream
    {
        $this->paused = true;
        return $this;
    }

    public function pipe(WritableObjectStream $destination, array $options = [])
    {
        if ($this === $destination) {
            throw new \LogicException('Cannot pipe a stream into itself.');
        }

        $destinationHash = spl_object_hash($destination);

        if (!isset($this->pipeDestroyers[$destinationHash])) {
            $destination->emit('pipe', [$this]);
        }

        if (!isset($this->pipeDestroyers[$destinationHash])) {
            $writer = function ($data) use ($destination) {
                $feedMore = $destination->write($data);
                if (false === $feedMore) {
                    $this->pause();
                }
            };

            /*
             * we use closures below instead of simple array callables to avoid clashes with callables
             * set up elsewhere (think about removeListener())
             */

            $resumer = function () {
                $this->resume();
            };

            $this->pipeDestroyers[$destinationHash] = [
                function () use ($destination, $writer, $resumer) {
                    $destination->emit('unpipe', [$this]);
                    $this->removeListener('data', $writer);
                    $destination->removeListener('drain', $resumer);
                }
            ];

            $this->on('data', $writer);
            $destination->on('drain', $resumer);
        }

        if ($options['end'] ?? true) {
            $ender = function () use ($destination) {
                $destination->end();
            };

            $this->pipeDestroyers[$destinationHash][] = function () use ($destination, $ender) {
                $this->removeListener('end', $ender);
            };

            $this->once('end', $ender);
        }

        return $destination;
    }

    public function read(int $size = null, bool $allowFewer = true) : array
    {
        if ($size < 0) {
            throw new \InvalidArgumentException('Size must be null or a non-negative integer.');
        }

        if (0 === $size) {
            try {
                $this->_read(1, $this->pushFn);
            } catch (\Throwable $e) {
                $this->emit('error', [$e]);
            }
            return [];
        }
        if ($this->readBuffer->isEmpty()) {
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

        if ($this->readEnded && $this->readBuffer->isEmpty()) {
            $this->ensureEndEmitted();
        }

        return $objects;
    }

    public function resume() : ReadableObjectStream
    {
        $this->paused = false;

        while (!$this->paused) {
            if ([] === $this->read(1)) {
                $this->read(0);
                if ([] === $this->read(1)) {
                    break;
                }
            }
        }

        return $this;
    }

    public function unpipe(WritableObjectStream $destination = null)
    {
        if (null === $destination) {
            $this->unpipeAll();
            return;
        }

        $destinationHash = spl_object_hash($destination);

        if (!isset($this->pipeDestroyers[$destinationHash])) {
            return;
        }

        $this->invokeAll($this->pipeDestroyers[$destinationHash]);
    }

    /** @internal */

    private function initReadable()
    {
        $this->readBuffer = new \SplQueue();

        $this->pushFn = function ($object, callable $onFlush = null) : bool {
            if (null === $object) {
                $this->endRead();
                return false;
            }

            if ($this->paused) {
                $readBufferWasEmpty = $this->readBuffer->isEmpty();
                $this->readBuffer->enqueue([$object, $onFlush]);
                if ($readBufferWasEmpty) {
                    $this->emit('readable');
                }
            } else {
                $this->emit('data', [$object]);
                if (null !== $onFlush) {
                    call_user_func($onFlush);
                }
            }

            return $this->readBuffer->count() < $this->pendingItemLimit;
        };
    }

    private function unpipeAll()
    {
        foreach ($this->pipeDestroyers as $destroyers) {
            $this->invokeAll($destroyers);
        }
    }

    private function invokeAll(array $closures)
    {
        foreach ($closures as $closure) {
            $closure();
        }
    }

    private function emitData($object, callable $onFlush = null)
    {
        $this->emit('data', [$object]);
        if ($onFlush) {
            call_user_func($onFlush);
        }
    }

    private function ensureEndEmitted()
    {
        if (!$this->readEndEmitted) {
            $this->readEndEmitted = true;
            $this->emit('end');
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

    private function endRead()
    {
        $this->readEnded = true;
        if ($this->readBuffer->isEmpty()) {
            $this->ensureEndEmitted();
        }
    }
}
