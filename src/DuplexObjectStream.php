<?php
namespace ObjectStream;

/**
 * @method DuplexObjectStream emit(string $event, array $args = [])
 * @method DuplexObjectStream on(string $event, callable $listener)
 * @method DuplexObjectStream once(string $event, callable $listener)
 * @method DuplexObjectStream removeListener(string $event, callable $listener)
 * @method DuplexObjectStream removeAllListeners(string $event = null)
 */
interface DuplexObjectStream extends ReadableObjectStream, WritableObjectStream
{

}
