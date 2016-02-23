<?php
namespace ObjectStream;

use Evenement\EventEmitterTrait;

function buffer(int $highWaterMark = 1) : DuplexObjectStream
{
    return new ObjectBuffer($highWaterMark);
}

function readable(\Iterator $source) : ReadableObjectStream
{
    $stream = buffer();
    $stream->pause();

    $stream->on('drain', function (DuplexObjectStream $stream) use ($source) {
        do {
            $source->next();
            if (!$source->valid()) {
                $stream->end();
                return;
            }
            $data = $source->current();
            $feedMore = $stream->write($data);
        } while ($feedMore);
    });

    foreach ($source as $item) {
        $feedMore = $stream->write($item);
        if (!$feedMore) {
            break;
        }
    }

    if (!$source->valid()) {
        $stream->end();
    }

    return $stream;
}

function writable(callable $writeFn, int $concurrency = 1) : WritableObjectStream
{
    return new class ($writeFn) implements WritableObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait;

        private $writeFn;

        public function __construct(callable $writeFn)
        {
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
    $first->on('error', $forwardError);

    foreach ($streams as $stream) {
        $last = $previous = $previous->pipe($stream);
        $last->on('error', $forwardError);
    }

    return $pipeline = composite($first, $last);
}

function composite(WritableObjectStream $writable, ReadableObjectStream $readable) : DuplexObjectStream
{
    return new class ($writable, $readable) implements DuplexObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamDecorator;
        use ReadableObjectStreamDecorator;
        use ReadableObjectStreamTrait {
            ReadableObjectStreamDecorator::isPaused insteadof ReadableObjectStreamTrait;
            ReadableObjectStreamDecorator::pause insteadof ReadableObjectStreamTrait;
            ReadableObjectStreamDecorator::read insteadof ReadableObjectStreamTrait;
            ReadableObjectStreamDecorator::resume insteadof ReadableObjectStreamTrait;
        }

        public function __construct(WritableObjectStream $writable, ReadableObjectStream $readable)
        {
            $this->setWritable($writable);
            $this->setReadable($readable);
        }
    };
}

function map(callable $mapFn, int $concurrency = 1) : DuplexObjectStream
{
    $transformFn = function ($object, callable $pushFn, callable $doneFn) use ($mapFn) {
        $mapFn($object, function ($error = null, $result = null) use ($pushFn, $doneFn) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                $pushFn($result);
                $doneFn();
            }
        });
    };

    return transform($transformFn, $concurrency);
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

function filter(callable $filterFn, int $concurrency = 1) : DuplexObjectStream
{
    $transformFn = function ($object, callable $pushFn, callable $doneFn) use ($filterFn) {
        $filterFn($object, function ($error = null, $keep = null) use ($object, $pushFn, $doneFn) {
            if (null !== $error) {
                $doneFn($error);
            } else {
                if ($keep) {
                    $pushFn($object);
                }
                $doneFn();
            }
        });
    };

    return transform($transformFn, $concurrency);
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

function flatten() : DuplexObjectStream
{
    $stream = null;

    $transformFn = function ($object, callable $pushFn, callable $doneFn, EventStream $drainEventStream) use (&$stream) {
        if (is_array($object)) {
            $object = new \ArrayIterator($object);
        }

        if ($object instanceof \Iterator) {
            $flow = function () use ($object, $pushFn, $doneFn, $drainEventStream, &$flow) {
                while ($object->valid()) {
                    $item = $object->current();
                    $feedMore = $pushFn($item);
                    $object->next();

                    if (!$feedMore) {
                        $drainEventStream->once($flow);
                        return;
                    }
                }

                $doneFn();
            };

            $flow();
        } else {
            $pushFn($object);
            $doneFn();
        }
    };

    return transform($transformFn);
}

function transform(callable $transformFn, int $concurrency = 1) : DuplexObjectStream
{
    return new class ($transformFn, $concurrency) implements DuplexObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait;
        use ReadableObjectStreamTrait;

        private $transformFn;

        public function __construct(callable $transformFn, int $concurrency)
        {
            $this->transformFn = $transformFn;
            $this->pendingItemLimit = $concurrency;
            $this->initWritable();
            $this->initReadable();
        }

        protected function _write($object, callable $onFlush)
        {
            call_user_func($this->transformFn, $object, $this->pushFn, $onFlush, $this->drainEventStream);
        }
    };
}

function through() : DuplexObjectStream
{
    return new class implements DuplexObjectStream {
        use EventEmitterTrait;
        use WritableObjectStreamTrait;
        use ReadableObjectStreamTrait;

        public function __construct()
        {
            $this->initReadable();
            $this->initWritable();
        }

        protected function _write($object, callable $onFlush)
        {
            try {
                call_user_func($this->pushFn, $object);
                $onFlush();
            } catch (\Throwable $e) {
                $onFlush($e);
            }
        }
    };
}

function iterator(ReadableObjectStream $stream, callable $waitFn) : \Iterator
{
    $stream->pause();

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
            yield $items[0];
        }
    }
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
            $this->observers[] = function (\Throwable $error = null, $result = null) use ($onSuccess, $onFailure) {
                if (null === $error) {
                    call_user_func($onSuccess, $result);
                } else {
                    call_user_func($onFailure, $error);
                }
            };
        }

        public function when(callable $callback, $cbData = null) : self
        {
            if ($this->isResolved) {
                $callback($this->error, $this->result, $cbData);
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
