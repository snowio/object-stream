<?php
namespace ObjectStream;

use ObjectStream\Exception\StreamEndedException;

trait WritableObjectStreamTrait
{
    private $corked = false;
    private $ended = false;
    private $finished = false;
    private $notifyDrain = false;
    private $pendingItemCount = 0;
    private $pendingItemLimit = 1;
    private $flushFn;
    private $drainEventStream;

    abstract protected function _write($object, callable $onFlush);

    public function cork()
    {
        $this->corked = true;
    }

    public function end($object = null, callable $onFinish = null)
    {
        if (null !== $object) {
            $this->write($object);
        }

        if ($this->ended) {
            if (null !== $onFinish) {
                if ($this->finished) {
                    call_user_func($onFinish, [$this]);
                } else {
                    $this->on('finish', $onFinish);
                }
            }
        } else {
            if (null !== $onFinish) {
                $this->on('finish', $onFinish);
            }

            $this->uncork();
            $this->ended = true;

            if (0 >= $this->pendingItemCount) {
                $this->ensureFinished();
            }
        }
    }

    public function uncork()
    {
        $this->corked = false;
    }

    public function write($object, callable $onFlush = null) : bool
    {
        if ($this->ended) {
            throw new StreamEndedException;
        }

        $this->pendingItemCount++;

        if (null !== $onFlush) {
            $onFlush = function ($error = null) use ($object, $onFlush) {
                call_user_func($onFlush, $error);
                call_user_func($this->flushFn);
            };
            $this->_write($object, $onFlush);
        } else {
            $this->_write($object, $this->flushFn);
        }

        if ($this->pendingItemCount >= $this->pendingItemLimit) {
            $this->notifyDrain = true;
            return false;
        }

        return true;
    }

    private function initWritable()
    {
        $this->flushFn = function ($error = null) {
            $this->pendingItemCount--;

            if ($error) {
                $this->emit('error', [$error, $this]);
            }

            if ($this->pendingItemCount <= 0) {
                $this->writable();

                if ($this->ended) {
                    $this->ensureFinished();
                }
            } elseif ($this->pendingItemCount < $this->pendingItemLimit) {
                $this->writable();
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
        $this->emit('finish', [$this]);
    }

    private function writable()
    {
        if ($this->notifyDrain) {
            $this->notifyDrain = false;
            $this->emit('drain', [$this]);
        }
    }
}
