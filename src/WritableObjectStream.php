<?php
namespace ObjectStream;

use Evenement\EventEmitterInterface;
use ObjectStream\Exception\StreamEndedException;

/**
 * @event drain
 * @event error
 * @event finish
 * @event pipe
 * @event unpipe
 */
interface WritableObjectStream extends EventEmitterInterface
{
    public function cork();

    public function end($object = null, callable $onFinish = null);

    public function uncork();

    /**
     * @throws StreamEndedException
     */
    public function write($object, callable $onFlush = null) : bool;
}
