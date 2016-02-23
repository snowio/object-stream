<?php
namespace ObjectStream;

use ObjectStream\Exception\StreamEndedException;

trait WritableObjectStreamDecorator
{
    /** @var WritableObjectStream */
    private $writable;

    private function setWritable(WritableObjectStream $writable)
    {
        $this->writable = $writable;

        foreach (['drain', 'error', 'finish', 'pipe', 'unpipe'] as $eventName) {
            $writable->on($eventName, function () use ($eventName) {
                $this->emit($eventName, func_get_args());
            });
        }
    }

    /** @see WritableObjectStream */

    public function cork()
    {
        $this->writable->cork();
    }

    public function end($object = null, callable $onFinish = null)
    {
        return $this->writable->end($object, $onFinish);
    }

    public function uncork()
    {
        $this->writable->uncork();
    }

    /**
     * @throws StreamEndedException
     */
    public function write($object, callable $onFlush = null) : bool
    {
        return $this->writable->write($object, $onFlush);
    }
}
