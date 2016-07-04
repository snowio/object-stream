<?php
namespace ObjectStream;

function buffer(array $options = []) : DuplexObjectStream
{
    return through($options);
}

function toArray(ReadableObjectStream $stream, callable $callback)
{
    $array = [];
    $stream->on('data', function ($item) use (&$array) {
        $array[] = $item;
    });
    $stream->once('error', $callback);
    $stream->once('end', function () use ($callback, &$array) {
        $callback(null, $array);
    });
}

function readable($source) : ReadableObjectStream
{
    if ($source instanceof ReadableObjectStream) {
        return $source;
    }

    if (is_array($source)) {
        $source = new \ArrayIterator($source);
    }

    if ($source instanceof \Iterator) {
        $source = function (int $size, callable $pushFn) use ($source) {
            $feedMore = true;
            do {
                if (!$source->valid()) {
                    $pushFn(null);
                    return;
                } else {
                    $value = $source->current();
                    $source->next();
                    if (null !== $value) {
                        $feedMore = $pushFn($value);
                    }
                }
            } while ($feedMore);
        };
    }

    return new class ($source) implements ReadableObjectStream {
        use EventEmitterTrait;
        use ReadableObjectStreamTrait;

        private $readFn;

        public function __construct(callable $readFn)
        {
            $this->readFn = $readFn;
            $this->initReadable();
        }

        protected function _read(int $size, callable $pushFn)
        {
            $result = call_user_func($this->readFn, $size, $pushFn);

            if (null === $result) {
                return;
            } elseif (method_exists($result, 'then')) {
                $result->then($pushFn);
            } elseif (method_exists($result, 'when')) {
                $result->when(function ($error = null, $result = null) use ($pushFn) {
                    if (!$error) {
                        $pushFn($result);
                    }
                });
            } else {
                $pushFn($result);
            }
        }
    };
}

function writable(callable $writeFn, array $options = []) : WritableObjectStream
{
    return new class ($writeFn, $options['concurrency'] ?? 1) implements WritableObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait;

        private $writeFn;

        public function __construct(callable $writeFn, int $concurrency)
        {
            $this->writeConcurrencyLimit = max(1, $concurrency);
            $this->writeFn = $writeFn;
            $this->initWritable();
        }

        protected function _write($object, callable $onFlush)
        {
            __callMaybeSync($this->writeFn, [$object, $onFlush, $this->drainEventStream], $onFlush);
        }
    };
}

function pipeline(DuplexObjectStream ...$streams) : DuplexObjectStream
{
    if (0 == count($streams)) {
        return through();
    }

    $pipeline = null;

    $forwardError = function ($error) use (&$pipeline) {
        $pipeline->emit('error', [$error]);
    };
    $first = $last = $previous = array_shift($streams);

    while (null !== $stream = array_shift($streams)) {
        $last = $previous = $previous->pipe($stream);

        if (0 != count($streams)) {
            $last->on('error', $forwardError);
        }
    }

    return $pipeline = composite($first, $last);
}

function composite(WritableObjectStream $writable, ReadableObjectStream $readable) : DuplexObjectStream
{
    return new class ($writable, $readable) implements DuplexObjectStream {
        use WritableObjectStreamDecorator;
        use ReadableObjectStreamDecorator;
        use EventEmitterTrait;

        public function __construct(WritableObjectStream $writable, ReadableObjectStream $readable)
        {
            $this->setWritable($writable);
            $this->setReadable($readable);
            $this->registerPersistentEvents('end', 'error', 'finish');

            foreach (['drain', 'error', 'finish', 'pipe', 'unpipe'] as $event) {
                $writable->on($event, function (...$args) use ($event) {
                    $this->emit($event, $args);
                });
            }

            foreach (['end', 'error', 'readable'] as $event) {
                $readable->on($event, function (...$args) use ($event) {
                    $this->emit($event, $args);
                });
            }

            // the data listener causes the stream to start flowing so only listen when there is a real listener
            $this->on('__listenersChanged', function () use ($readable, &$dataListener) {
                if ($dataListener) {
                    if (0 == count($this->listeners('data'))) {
                        $readable->removeListener('data', $dataListener);
                        $dataListener = null;
                    }
                } elseif (0 != count($this->listeners('data'))) {
                    $readable->on('data', $dataListener = function ($data) {
                        $this->emit('data', [$data]);
                    });
                }
            });
        }
    };
}

function map(callable $mapFn, array $options = []) : DuplexObjectStream
{
    $transformFn = function ($object, callable $pushFn, callable $doneFn) use ($mapFn) {
        $callback = function ($error = null, $result = null) use ($pushFn, $doneFn) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                $pushFn($result, $doneFn);
            }
        };

        __callMaybeSync($mapFn, [$object, $callback], $callback);
    };

    return transform($transformFn, null, $options);
}

/**
 * @deprecated Use map() directly
 */
function mapSync(callable $mapFn) : DuplexObjectStream
{
    return map($mapFn);
}

function filter(callable $predicate, array $options = []) : DuplexObjectStream
{
    $transformFn = function ($object, callable $pushFn, callable $doneFn) use ($predicate) {
        $callback = function ($error = null, $keep = null) use ($object, $pushFn, $doneFn) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                if ($keep) {
                    $pushFn($object, $doneFn);
                } else {
                    $doneFn();
                }
            }
        };

        __callMaybeSync($predicate, [$object, $callback], $callback);
    };

    return transform($transformFn, null, $options);
}

/**
 * @deprecated Use filter() directly
 */
function filterSync(callable $predicate) : DuplexObjectStream
{
    return filter($predicate);
}

function concat() : DuplexObjectStream
{
    $inputMap = mapSync(function ($source) {
        $source = readable($source);
        $sourceOutputBuffer = $source->pipe(buffer());
        $source->once('error', function ($error) use ($sourceOutputBuffer) {
            $sourceOutputBuffer->emit('error', [$error]);
        });
        if (!$source->isPaused()) {
            $source->resume();
        }
        return $sourceOutputBuffer;
    });

    $concatOutputBuffer = buffer();

    $streamHandler = $inputMap->pipe(writable(function (ReadableObjectStream $source, callable $doneFn) use ($concatOutputBuffer) {
        $source->once('end', $doneFn);
        $source->once('error', $doneFn);
        $source->pipe($concatOutputBuffer, ['end' => false]);
    }, ['concurrency' => 1]));

    $streamHandler->once('finish', [$concatOutputBuffer, 'end']);

    $composite = composite($inputMap, $concatOutputBuffer);
    $streamHandler->once('error', function ($error) use ($composite) {
        $composite->emit('error', [$error]);
    });

    return $composite;
}

function flatten(array $options = []) : DuplexObjectStream
{
    return transform(function ($source, callable $pushFn, callable $doneFn, EventStream $drainEventStream) {
        $source = readable($source);
        $source->once('end', $doneFn);
        $source->once('error', $doneFn);
        $source->on('data', function ($data) use ($pushFn, $source, $drainEventStream) {
            if (!$pushFn($data)) {
                $source->pause();
                $drainEventStream->once([$source, 'resume']);
            }
        });
        if (!$source->isPaused()) {
            $source->resume();
        }
    }, null, $options);
}

function chunk($predicateOrSize) : DuplexObjectStream
{
    if (is_int($predicateOrSize)) {
        if ($predicateOrSize < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1.');
        }

        $desiredChunkSize = $predicateOrSize;
        $currentChunkSize = 0;
        $predicate = function () use ($desiredChunkSize, &$currentChunkSize) {
            if (!$continueChunk = ++$currentChunkSize <= $desiredChunkSize) {
                $currentChunkSize = 1;
            }
            return $continueChunk;
        };
    } elseif (!is_callable($predicateOrSize)) {
        throw new \InvalidArgumentException('Callable or positive int expected.');
    } else {
        $predicate = $predicateOrSize;
    }

    $currentChunk = null;
    $chunker = transform(function ($item, callable $pushFn, callable $doneFn) use ($predicate, &$currentChunk) {
        $callback = function ($error, $continueChunk = null) use ($item, $pushFn, $doneFn, &$currentChunk) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                if (!$continueChunk) { // terminate this chunk
                    if ($currentChunk) {
                        $currentChunk->end();
                    }
                    $currentChunk = buffer();
                    $pushFn($currentChunk);
                } elseif (!$currentChunk) {
                    $currentChunk = buffer();
                    $pushFn($currentChunk);
                }
                $currentChunk->write($item);
                $doneFn();
            }
        };

        __callMaybeSync($predicate, [$item, $callback], $callback);
    }, null, ['concurrency' => 1]);

    $chunker->once('end', function () use (&$currentChunk, $chunker) {
        if ($currentChunk) {
            $currentChunk->end();
        }
    });

    $inputBuffer = buffer();
    $inputBuffer->pipe($chunker);

    return composite($inputBuffer, $chunker);
}

function transform(callable $transformFn, callable $flushFn = null, array $options = []) : DuplexObjectStream
{
    return new class ($transformFn, $options['concurrency'] ?? 1, $options['highWaterMark'] ?? 1) implements DuplexObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait { isWritable as _isWritable; }
        use ReadableObjectStreamTrait;

        private $transformFn;

        public function __construct(callable $transformFn, int $concurrency, int $highWaterMark)
        {
            $this->on('finish', [$this, 'endRead']);
            $this->transformFn = $transformFn;
            $this->writeConcurrencyLimit = max(1, $concurrency);
            $this->highWaterMark = max(1, $highWaterMark);
            $this->initWritable();
            $this->initReadable();

            $origPushFn = $this->pushFn;
            $this->pushFn = function ($object, callable $onFlush = null) use ($origPushFn) {
                return $origPushFn($object, function ($error = null) use ($onFlush) {
                    try {
                        if ($onFlush) {
                            $onFlush($error);
                        }
                    } finally {
                        if ($this->isWritable()) {
                            $this->writable();
                        }
                    }
                });
            };
        }

        protected function _write($object, callable $onFlush)
        {
            call_user_func($this->transformFn, $object, $this->pushFn, $onFlush, $this->drainEventStream);
        }

        private function isWritable()
        {
            return $this->_isWritable() && $this->readBuffer->count() < $this->highWaterMark;
        }
    };
}

function through(array $options = []) : DuplexObjectStream
{
    return new class ($options['highWaterMark'] ?? 1) implements DuplexObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait;
        use ReadableObjectStreamTrait;

        public function __construct(int $highWaterMark)
        {
            $this->on('finish', [$this, 'ensureEndEmitted']);
            $this->writeConcurrencyLimit = max(1, $highWaterMark);
            $this->highWaterMark = max(1, $highWaterMark);
            $this->initWritable();
            $this->initReadable();
        }

        /** @see WritableObjectStream */

        protected function _write($object, callable $onFlush)
        {
            call_user_func($this->pushFn, $object, $onFlush);
        }
    };
}

function iterator(ReadableObjectStream $stream, callable $waitFn) : \Iterator
{
    $fn = function (ReadableObjectStream $stream, callable $waitFn) {
        $ended = false;

        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        while (!$ended) {
            $items = $stream->read(1);

            if (empty($items)) {
                $stream->read(0);
                $items = $stream->read(1);
            }

            if (empty($items)) {
                $promise = __promise($graceful = true);
                $stream->once('readable', [$promise, 'succeed']);
                $stream->once('end', [$promise, 'succeed']);
                $stream->once('error', [$promise, 'fail']);
                $waitFn($promise);
            } else {
                foreach ($items as $item) {
                    yield $item;
                }
            }
        }
    };

    return $fn($stream, $waitFn);
}

/**
 * @todo Add coroutine support?
 */
function __callMaybeSync(callable $function, array $args, callable $callback)
{
    try {
        $result = call_user_func_array($function, $args);
        if (null === $result) {
            return;
        } elseif (method_exists($result, 'then')) {
            $result->then(
                function ($result = null) use ($callback) {
                    $callback(null, $result);
                },
                $callback
            );
        } elseif (method_exists($result, 'when')) {
            $result->when($callback);
        } else {
            $callback(null, $result);
        }
    } catch (\Throwable $e) {
        $callback($e);
    }
}

function __fulfilledPromise($result) : Promise
{
    return __promise($graceful = false)->succeed($result);
}

function __promise(bool $graceful) : Promise
{
    return new class ($graceful) implements Promise {
        private $graceful;
        private $isResolved = false;
        private $error;
        private $result;
        private $observers = [];

        public function __construct(bool $graceful)
        {
            $this->graceful = $graceful;
        }

        public function isResolved() : bool
        {
            return $this->isResolved;
        }

        public function then(callable $onSuccess = null, callable $onFailure = null)
        {
            $this->when(function (\Throwable $error = null, $result = null) use ($onSuccess, $onFailure) {
                if (null === $error) {
                    call_user_func($onSuccess, $result);
                } else {
                    call_user_func($onFailure, $error);
                }
            });
        }

        public function when(callable $callback, ...$args) : Promise
        {
            if ($this->isResolved) {
                call_user_func($callback, $this->error, $this->result, ...$args);
            } else {
                $this->observers[] = [$callback, $args];
            }

            return $this;
        }

        public function succeed($result = null) : Promise
        {
            return $this->resolve(null, $result);
        }

        public function fail(\Throwable $error) : Promise
        {
            return $this->resolve($error);
        }

        public function resolve(\Throwable $error = null, $result = null) : Promise
        {
            if ($this->isResolved) {
                if ($this->graceful) {
                    return $this;
                }
                throw new \LogicException('Promise already resolved');
            }

            $this->isResolved = true;
            $this->error = $error;
            $this->result = $result;

            while (null !== $observer = array_shift($this->observers)) {
                $this->when($observer[0], ...$observer[1]);
            }

            return $this;
        }
    };
}
