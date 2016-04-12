<?php
namespace ObjectStream;

use ObjectStream\Exception\StreamEndedException;

trait WritableObjectStreamTrait
{
    private $corked = false;
    private $writeEnded = false;
    private $finished = false;
    private $notifyDrain = false;
    private $writeConcurrency = 0;
    private $writeConcurrencyLimit = 1;
    private $flushFn;
    private $drainEventStream;
    /** @var \SplQueue */
    private $writeBuffer;

    abstract protected function _write($object, callable $flushFn);

    public function cork()
    {
        $this->corked = true;
    }

    public function end($object = null, callable $onFinish = null)
    {
        if (null !== $object) {
            $this->write($object);
        }

        if ($this->writeEnded) {
            if (null !== $onFinish) {
                if ($this->finished) {
                    call_user_func($onFinish);
                } else {
                    $this->on('finish', $onFinish);
                }
            }
        } else {
            if (null !== $onFinish) {
                $this->on('finish', $onFinish);
            }

            $this->uncork();
            $this->writeEnded = true;

            if (0 >= $this->writeConcurrency) {
                $this->ensureFinished();
            }
        }
    }

    public function uncork()
    {
        if (!$this->corked) {
            return;
        }

        while (!$this->corked && !$this->writeBuffer->isEmpty()) {
            $this->doWrite(...$this->writeBuffer->dequeue());
        }
    }

    public function write($object, callable $onFlush = null) : bool
    {
        if ($this->writeEnded) {
            throw new StreamEndedException;
        }

        $this->writeConcurrency++;

        if ($this->corked) {
            $this->writeBuffer->enqueue([$object, $onFlush]);
        } else {
            $this->doWrite($object, $onFlush);
        }

        if ($this->writeConcurrency >= $this->writeConcurrencyLimit) {
            $this->notifyDrain = true;
            return false;
        }

        return true;
    }

    private function doWrite($object, callable $onFlush = null)
    {
        if (null !== $onFlush) {
            $onFlush = function ($error = null) use ($object, $onFlush) {
                call_user_func($onFlush, $error);
                call_user_func($this->flushFn);
            };
        } else {
            $onFlush = $this->flushFn;
        }

        try {
            $this->_write($object, $onFlush);
        } catch (\Throwable $e) {
            $onFlush($e);
        }
    }

    private function initWritable()
    {
        $this->writeBuffer = new \SplQueue();

        $this->registerPersistentEvents('error', 'finish');

        $this->flushFn = function ($error = null) {
            $this->writeConcurrency--;

            if ($error) {
                $this->emit('error', [$error]);
            }

            if ($this->writeConcurrency < $this->writeConcurrencyLimit) {
                $this->writable();
            }

            if ($this->writeConcurrency <= 0 && $this->writeEnded) {
                // invoking writable() can cause $this->writeConcurrency to change!!
                $this->ensureFinished();
            }
        };

        $this->drainEventStream = new EventStream($this, 'drain');
    }

    private function ensureFinished()
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->emit('finish');
    }

    private function writable()
    {
        if ($this->notifyDrain) {
            $this->notifyDrain = false;

            foreach ($this->listeners('drain') as $listener) {
                if ($this->writeConcurrency >= $this->writeConcurrencyLimit) {
                    $this->notifyDrain = true;
                    break;
                }

                call_user_func($listener);
            }
        }
    }
}
