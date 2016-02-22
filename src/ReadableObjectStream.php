<?php
namespace ObjectStream;

use Evenement\EventEmitterInterface;

/**
 * @event data
 * @event end
 * @event error
 * @event readable
 */
interface ReadableObjectStream extends EventEmitterInterface
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
