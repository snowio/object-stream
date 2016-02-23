<?php
namespace ObjectStream;

trait ReadableObjectStreamTrait
{
    private $pipeDestroyers = [];
    private $paused = false;
    private $pushFn;

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
        return [];
    }

    public function resume() : ReadableObjectStream
    {
        $this->paused = false;
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

    private function initReadable()
    {
        $this->pushFn = function ($object) : bool {
            $this->emit('data', [$object]);
            return !$this->paused;
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
}
