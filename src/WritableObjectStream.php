<?php
namespace ObjectStream;

use ObjectStream\Exception\StreamEndedException;

/**
 * @event drain
 * @event error
 * @event finish
 * @event pipe
 * @event unpipe
 *
 * @method WritableObjectStream emit(string $event, array $args = [])
 * @method WritableObjectStream on(string $event, callable $listener)
 * @method WritableObjectStream once(string $event, callable $listener)
 * @method WritableObjectStream removeListener(string $event, callable $listener)
 * @method WritableObjectStream removeAllListeners(string $event = null)
 */
interface WritableObjectStream extends EventEmitter
{
    public function cork();

    public function end($object = null, callable $onFinish = null);

    public function uncork();

    /**
     * @throws StreamEndedException
     */
    public function write($object, callable $onFlush = null) : bool;
}
