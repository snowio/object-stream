<?php
namespace ObjectStream;

/**
 * @event data
 * @event end
 * @event error
 * @event readable
 *
 * @method ReadableObjectStream emit(string $event, array $args = [])
 * @method ReadableObjectStream on(string $event, callable $listener)
 * @method ReadableObjectStream once(string $event, callable $listener)
 * @method ReadableObjectStream removeListener(string $event, callable $listener)
 * @method ReadableObjectStream removeAllListeners(string $event = null)
 */
interface ReadableObjectStream extends EventEmitter
{
    public function isPaused() : bool;

    public function pause() : self;

    /**
     * @return WritableObjectStream|DuplexObjectStream The $destination stream
     */
    public function pipe(WritableObjectStream $destination, array $options = []);

    public function read(int $size = null, bool $allowFewer = true) : array;

    public function resume() : self;

    public function unpipe(WritableObjectStream $destination = null);
}
