<?php
namespace ObjectStream;

function buffer(array $options = []) : DuplexObjectStream
{
    return through($options);
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
            call_user_func($this->readFn, $size, $pushFn);
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
            $this->pendingItemLimit = max(1, $concurrency);
            $this->writeFn = $writeFn;
            $this->initWritable();
        }

        protected function _write($object, callable $onFlush)
        {
            call_user_func($this->writeFn, $object, $onFlush, $this->drainEventStream);
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

            foreach (['data', 'end', 'error', 'readable'] as $event) {
                $readable->on($event, function (...$args) use ($event) {
                    $this->emit($event, $args);
                });
            }
        }
    };
}

function map(callable $mapFn, array $options = []) : DuplexObjectStream
{
    $transformFn = function ($object, callable $pushFn, callable $doneFn) use ($mapFn) {
        $mapFn($object, function ($error = null, $result = null) use ($pushFn, $doneFn) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                $pushFn($result, $doneFn);
            }
        });
    };

    return transform($transformFn, null, $options);
}

function mapSync(callable $mapFn) : DuplexObjectStream
{
    return map(function ($object, callable $callback) use ($mapFn) {
        try {
            $callback(null, $mapFn($object));
        } catch (\Throwable $e) {
            $callback($e);
        }
    });
}

function filter(callable $filterFn, array $options = []) : DuplexObjectStream
{
    $transformFn = function ($object, callable $pushFn, callable $doneFn) use ($filterFn) {
        $filterFn($object, function ($error = null, $keep = null) use ($object, $pushFn, $doneFn) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                if ($keep) {
                    $pushFn($object, $doneFn);
                } else {
                    $doneFn();
                }
            }
        });
    };

    return transform($transformFn, null, $options);
}

function filterSync(callable $filterFn) : DuplexObjectStream
{
    return filter(function ($object, callable $callback) use ($filterFn) {
        try {
            $callback(null, $filterFn($object));
        } catch (\Throwable $e) {
            $callback($e);
        }
    });
}

function concat() : DuplexObjectStream
{
    $inputMap = mapSync(function ($source) {
        $source = readable($source);
        $sourceOutputBuffer = $source->pipe(buffer()->pause());
        $source->once('error', function ($error) use ($sourceOutputBuffer) {
            $sourceOutputBuffer->emit('error', [$error]);
        });
        if (!$source->isPaused()) {
            $source->resume();
        }
        return $sourceOutputBuffer;
    });

    $concatOutputBuffer = buffer();

    $streamHandler = $inputMap->pipe($w = writable(function (ReadableObjectStream $source, callable $doneFn) use ($concatOutputBuffer) {
        $source->once('end', $doneFn);
        $source->once('error', $doneFn);
        $source->pipe($concatOutputBuffer, ['end' => false]);
        $source->resume();
    }, ['concurrency' => 1]));

    $streamHandler->once('finish', [$concatOutputBuffer, 'end']);

    $composite = composite($inputMap, $concatOutputBuffer);
    $w->once('error', function ($error) use ($composite) {
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

function transform(callable $transformFn, callable $flushFn = null, array $options = []) : DuplexObjectStream
{
    return new class ($transformFn, $options['concurrency'] ?? 1) implements DuplexObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait;
        use ReadableObjectStreamTrait;

        private $transformFn;

        public function __construct(callable $transformFn, int $concurrency)
        {
            $this->on('finish', [$this, 'ensureEndEmitted']);
            $this->transformFn = $transformFn;
            $this->pendingItemLimit = max(1, $concurrency);
            $this->initWritable();
            $this->initReadable();
        }

        protected function _write($object, callable $onFlush)
        {
            call_user_func($this->transformFn, $object, $this->pushFn, $onFlush, $this->drainEventStream);
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
            $this->pendingItemLimit = max(1, $highWaterMark);
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
    $stream->pause();

    $fn = function (ReadableObjectStream $stream, callable $waitFn) {
        $ended = false;

        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        while (!$ended) {
            $items = $stream->read(1);

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

function __promise(bool $graceful)
{
    return new class ($graceful) {
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

        public function when(callable $callback, $cbData = null) : self
        {
            if ($this->isResolved) {
                if ($cbData === null) {
                    $callback($this->error, $this->result);
                } else {
                    $callback($this->error, $this->result, $cbData);
                }
            } else {
                $this->observers[] = [$callback, $cbData];
            }

            return $this;
        }

        public function succeed($result = null) : self
        {
            return $this->resolve(null, $result);
        }

        public function fail(\Throwable $error) : self
        {
            return $this->resolve($error);
        }

        public function resolve(\Throwable $error = null, $result = null) : self
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
                $this->when($observer[0], $observer[1]);
            }

            return $this;
        }
    };
}
