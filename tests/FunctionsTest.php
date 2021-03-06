<?php
namespace ObjectStream\Test;

use ObjectStream\DuplexObjectStream;
use function ObjectStream\pipeline;
use function ObjectStream\buffer;
use function ObjectStream\mapSync;
use function ObjectStream\through;
use function ObjectStream\iterator;
use function ObjectStream\readable;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\Timer\timeout;
use function Clue\React\Block\await;
use function ObjectStream\map;
use function ObjectStream\writable;
use function ObjectStream\transform;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    /** @var LoopInterface */
    private $eventLoop;

    public function setUp()
    {
        $this->eventLoop = Factory::create();
    }

    public function testTransformerPause()
    {
        $pipeline = pipeline(
            buffer(['highWaterMark' => 5]),
            transform(function ($item, callable $pushFn, callable $flushFn) {
                $pushFn($item);
                $flushFn();
            }),
            buffer(['highWaterMark' => 5])
        );

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($pipeline->write('foo'));
        }

        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($pipeline->write('foo'));
        }
    }

    public function testCompositeAwaitsDataListener()
    {
        $stream = \ObjectStream\composite($input = buffer(), $input->pipe(buffer()));

        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        $stream->write('foo');
        $stream->write('bar');
        $stream->end();

        $this->assertNull($ended);

        $stream->on('data', $l = function ($_data) use (&$data, &$l, $stream) {
            $data = $_data;
            $stream->removeListener('data', $l);
        });

        $this->assertSame('foo', $data);
        $this->assertNull($ended);

        $stream->on('data', function ($_data) use (&$data) {
            $data = $_data;
        });

        $this->assertSame('bar', $data);
        $this->assertTrue($ended);
    }

    public function testImplicitPause()
    {
        $buffer = buffer();

        $buffer->end(1);
        $buffer->resume();
        $buffer->on('end', function () use (&$ended) { $ended = true; });

        $this->assertNull($ended);

        $buffer->on('data', function ($_data) use (&$data) { $data = $_data; });

        $this->assertTrue($ended);
        $this->assertSame(1, $data);
    }

    public function testExplicitPause()
    {
        $buffer = buffer()->pause();

        $buffer->end(1);
        $buffer->on('end', function () use (&$ended) { $ended = true; });
        $buffer->on('data', function ($_data) use (&$data) { $data = $_data; });

        $this->assertNull($ended);

        $buffer->resume();

        $this->assertTrue($ended);
        $this->assertSame(1, $data);
    }

    public function testChunkerPauseResume()
    {
        $pipeline = pipeline(
            \ObjectStream\chunk($chunkSize = 1),
            \ObjectStream\concat()
        )->pause();

        for ($i = 1; $i <= 100; $i++) {
            $pipeline->write($i);
        }
        $pipeline->end();

        \ObjectStream\toArray($pipeline, function ($_error = null, $_output = null) use (&$error, &$output) {
            $error = $_error;
            $output = $_output;
        });

        $pipeline->pipe($destination = writable(function ($item, callable $callback) {
            $this->eventLoop->addTimer(0, function () use ($callback) {$callback();});
        }), ['concurrency' => 10]);

        if ($error) {
            throw $error;
        }

        $pipeline->resume();

        $finished = new Deferred;

        $destination->on('finish', [$finished, 'resolve']);
        $destination->on('error', [$finished, 'reject']);

        $this->await($finished->promise(), 0.1);

        $this->assertSame(range(1, 100), $output);
    }

    public function testChunkInt()
    {
        $chunker = \ObjectStream\chunk($chunkSize = 10);

        $arrayStream = $chunker->pipe(map('\ObjectStream\toArray'));

        \ObjectStream\toArray($arrayStream, function ($error = null, $_arrays) use (&$arrays) {
            $arrays = $_arrays;
        });

        $expectedArrays = [];

        for ($i = 1; $i <= 100; $i++) {
            $expectedArrays[floor(($i - 1) / $chunkSize)][] = $i;
            $chunker->write($i);
        }
        $chunker->end();

        $this->assertSame($expectedArrays, $arrays);
    }

    public function testToArray()
    {
        $buffer = buffer();

        $array = null;
        \ObjectStream\toArray($buffer, function ($error, $_array) use (&$array) {
            $array = $_array;
        });

        for ($i = 1; $i <= 100; $i++) {
            $buffer->write($i);
        }

        $buffer->end();

        $this->assertSame(range(1, 100), $array);
    }

    public function testConcatErrorForwarding()
    {
        $thrown = new \Exception;

        $concat = \ObjectStream\concat();

        $source = readable((function () use ($thrown) {
            if (false) {
                yield 'foo';
            }
            throw $thrown;
        })());

        $concat->write($source);

        $emitted = false;

        $concat->on('error', function ($error) use (&$emitted) {
            $emitted = $error;
        });

        $this->assertEquals($thrown, $emitted);
    }

    public function testEarlyEndBuffer()
    {
        $ended = false;
        $buffer = buffer();

        $buffer->end();

        $buffer->on('end', function () use (&$ended) {
            $ended = true;
        });

        $this->assertTrue($ended);
    }

    public function testEmptyConcatSource()
    {
        $concat = \ObjectStream\concat();

        $source = readable((function () {
            if (false) {
                yield 'foo';
            }
        })());

        $concat->write($source);
        $concat->end();

        $ended = false;

        $concat->on('end', function () use (&$ended) {
            $ended = true;
        });

        $this->assertTrue($ended);
    }

    public function testFlattenArrays()
    {
        $arrays = array_map(function ($n) {
            return range(10 * $n + 1, 10 * $n + 10);
        }, range(0, 9));

        shuffle($arrays);

        $flatten = \ObjectStream\flatten();

        $bucket = [];
        $ended = false;

        $flatten->on('data', function ($item) use (&$bucket) {
            $bucket[] = $item;
        });
        $flatten->on('end', function () use (&$ended) {
            $ended = true;
        });

        foreach ($arrays as $array) {
            $flatten->write($array);
        }
        $flatten->end();

        $this->assertTrue($ended);
        sort($bucket);
        $this->assertEquals(range(1, 100), $bucket);
    }

    public function testFlattenIterators()
    {
        $iterators = array_map(function ($n) {
            return (function () use ($n) {
                for ($i = 1; $i <= 10; $i++) {
                    yield $i + $n * 10;
                }
            })();
        }, range(0, 9));

        shuffle($iterators);

        $flatten = \ObjectStream\flatten();

        $bucket = [];
        $ended = false;

        $flatten->on('data', function ($item) use (&$bucket) {
            $bucket[] = $item;
        });
        $flatten->on('end', function () use (&$ended) {
            $ended = true;
        });

        foreach ($iterators as $iterator) {
            $flatten->write($iterator);
        }
        $flatten->end();

        $this->assertTrue($ended);
        sort($bucket);
        $this->assertEquals(range(1, 100), $bucket);
    }

    public function testFlattenStreams()
    {
        $streams = array_map(function ($n) {
            return readable((function () use ($n) {
                for ($i = 1; $i <= 10; $i++) {
                    yield $i + $n * 10;
                }
            })());
        }, range(0, 9));

        shuffle($streams);

        $flatten = \ObjectStream\flatten();

        $bucket = [];
        $ended = false;

        $flatten->on('data', function ($item) use (&$bucket) {
            $bucket[] = $item;
        });
        $flatten->on('end', function () use (&$ended) {
            $ended = true;
        });

        foreach ($streams as $stream) {
            $flatten->write($stream);
        }
        $flatten->end();

        $this->assertTrue($ended);
        sort($bucket);
        $this->assertEquals(range(1, 100), $bucket);
    }

    public function testConcatArrays()
    {
        $arrays = array_map(function ($n) {
            return range(10 * $n + 1, 10 * $n + 10);
        }, range(0, 9));

        $concat = \ObjectStream\concat()->pause();

        $bucket = [];
        $ended = false;

        $concat->on('data', function ($item) use (&$bucket) {
            $bucket[] = $item;
        });
        $concat->on('end', function () use (&$ended) {
            $ended = true;
        });

        foreach ($arrays as $array) {
            $concat->write($array);
        }
        $concat->end();

        $this->assertFalse($ended);
        $this->assertEmpty($bucket);

        shuffle($arrays);

        $concat->resume();

        $this->assertTrue($ended);
        $this->assertSame(range(1, 100), $bucket);
    }

    public function testConcatIterators()
    {
        $iterators = array_map(function ($n) {
            return (function () use ($n) {
                for ($i = 1; $i <= 10; $i++) {
                    yield $i + $n * 10;
                }
            })();
        }, range(0, 9));

        $concat = \ObjectStream\concat()->pause();

        $bucket = [];
        $ended = false;

        $concat->on('data', function ($item) use (&$bucket) {
            $bucket[] = $item;
        });
        $concat->on('end', function () use (&$ended) {
            $ended = true;
        });

        foreach ($iterators as $stream) {
            $concat->write($stream);
        }
        $concat->end();

        $this->assertFalse($ended);
        $this->assertEmpty($bucket);

        shuffle($iterators);

        $concat->resume();

        $this->assertTrue($ended);
        $this->assertSame(range(1, 100), $bucket);
    }

    public function testConcatStreams()
    {
        $streams = array_map(function ($n) {
            return readable((function () use ($n) {
                for ($i = 1; $i <= 10; $i++) {
                    yield $i + $n * 10;
                }
            })())->pause();
        }, range(0, 9));

        $concat = \ObjectStream\concat();

        $bucket = [];
        $ended = false;

        $concat->on('data', function ($item) use (&$bucket) {
            $bucket[] = $item;
        });
        $concat->on('end', function () use (&$ended) {
            $ended = true;
        });

        foreach ($streams as $stream) {
            $concat->write($stream);
        }
        $concat->end();

        $this->assertFalse($ended);
        $this->assertEmpty($bucket);

        shuffle($streams);

        foreach ($streams as $stream) {
            $stream->resume();
        }

        $this->assertTrue($ended);
        $this->assertSame(range(1, 100), $bucket);
    }

    public function testWritableConcurrency()
    {
        $concurrency = 0;
        $peakConcurrency = 0;

        $buffer = buffer();

        $writable = writable(function ($value, callable $callback) use (&$concurrency, &$peakConcurrency) {
            $concurrency++;
            $peakConcurrency = max($peakConcurrency, $concurrency);
            $this->eventLoop->addTimer(0, function () use (&$concurrency, $callback) {
                $concurrency--;
                $callback();
            });
        }, ['concurrency' => 2]);

        $finished = new Deferred;

        $writable->on('finish', [$finished, 'resolve']);
        $writable->on('error', [$finished, 'reject']);

        $buffer->pipe($writable);

        for ($i = 0; $i < 100; $i++) {
            $buffer->write($i);
        }

        $this->assertEquals(2, $concurrency);
        $this->assertLessThanOrEqual(2, $peakConcurrency);

        $buffer->end();

        $this->assertEquals(2, $concurrency);
        $this->assertLessThanOrEqual(2, $peakConcurrency);

        $this->await($finished->promise(), 0.1);
    }

    public function testPipelineErrorForwarding()
    {
        for ($i = 1; $i <= 3; $i++) {
            $thrown = new \Exception;
            $emitted = [];

            switch ($i) {
                case 1:
                    $pipeline = pipeline(
                        mapSync(function () use ($thrown) {
                            throw $thrown;
                        }),
                        buffer(),
                        buffer()
                    );
                    break;

                case 2:
                    $pipeline = pipeline(
                        buffer(),
                        mapSync(function () use ($thrown) {
                            throw $thrown;
                        }),
                        buffer()
                    );
                    break;

                case 3:
                    $pipeline = pipeline(
                        buffer(),
                        buffer(),
                        mapSync(function () use ($thrown) {
                            throw $thrown;
                        })
                    );
                    break;
            }

            $pipeline->on('error', function ($error) use (&$emitted) {
                $emitted[] = $error;
            });

            $pipeline->write('foo');

            $this->assertSame([$thrown], $emitted);
        }
    }

    public function testDataObserverErrorBubbles()
    {
        $error = new \Exception;

        $buffer = buffer();
        $buffer->on('data', function () use ($error) {
            throw $error;
        });

        $emitted = null;

        $buffer->on('error', function ($e) use (&$emitted) {
            $emitted = $e;
        });

        $this->eventLoop->nextTick(function () use ($buffer) {
            $buffer->write('foo');
        });

        $this->eventLoop->tick();

        $this->assertSame($error, $emitted);
    }

    public function testReadableEmitsError()
    {
        $error = new \Exception;

        $readable = readable(function () use ($error) {
            throw $error;
        });

        $emitted = null;

        $readable->on('error', function ($e) use (&$emitted) {
            $emitted = $e;
        });

        $this->eventLoop->nextTick(function () use ($readable) {
            $readable->read(0);
        });

        $this->eventLoop->tick();

        $this->assertSame($error, $emitted);
    }

    public function testWritableEmitsError()
    {
        $error = new \Exception;

        $writable = writable(function () use ($error) {
            throw $error;
        });

        $emitted = null;

        $writable->on('error', function ($e) use (&$emitted) {
            $emitted = $e;
        });

        $this->eventLoop->nextTick(function () use ($writable) {
            $writable->write('foo');
        });

        $this->eventLoop->tick();

        $this->assertSame($error, $emitted);
    }

    public function testMapEndWithPendingItems()
    {
        $map = map(function ($n, callable $callback) {
            $this->eventLoop->nextTick(function () use ($callback, $n) {
                $callback(null, 2 * $n);
            });
        }, ['concurrency' => 5]);

        $items = [];
        $ended = false;

        $map->on('data', function ($n) use (&$items) {
            $items[] = $n;
        });

        $map->on('end', function () use (&$ended) {
            $ended = true;
        });

        for ($i = 0; $i < 10; $i++) {
            $map->write($i);
        }
        $map->end();

        $this->assertFalse($ended);
        $this->assertEmpty($items);

        $this->eventLoop->tick();

        $this->assertTrue($ended);
        $this->assertSame(range(0, 18, 2), $items);
    }

    public function testZeroHighWaterMark()
    {
        $pipeline = pipeline(
            buffer(['highWaterMark' => 0]),
            buffer(['highWaterMark' => 0]),
            buffer(['highWaterMark' => 0])->pause()
        );

        $items = [];
        $ended = false;

        $pipeline->on('data', $onData = function ($item) use (&$items) {
            $items[] = $item;
        });
        $pipeline->on('end', function () use ($pipeline, $onData, &$ended) {
            $pipeline->removeListener('data', $onData);
            $ended = true;
        });

        for ($i = 0; $i < 100; $i++) {
            $pipeline->write($i);
        }

        $pipeline->end();
        $pipeline->resume();

        $this->assertSame(range(0, $i - 1), $items);
        $this->assertTrue($ended);
    }

    public function testBufferPipeline()
    {
        $pipeline = pipeline(
            buffer(['highWaterMark' => 1]),
            buffer(['highWaterMark' => 1]),
            buffer(['highWaterMark' => 1])->pause()
        );

        $items = [];
        $ended = false;

        $pipeline->on('data', $onData = function ($item) use (&$items) {
            $items[] = $item;
        });
        $pipeline->on('end', function () use ($pipeline, $onData, &$ended) {
            $pipeline->removeListener('data', $onData);
            $ended = true;
        });

        for ($i = 0; $i < 100; $i++) {
            $pipeline->write($i);
        }

        $pipeline->end();
        $pipeline->resume();

        $this->assertSame(range(0, $i - 1), $items);
        $this->assertTrue($ended);
    }

    public function testBuffer()
    {
        $this->_testBufferedDuplex(
            buffer(['highWaterMark' => 20]),
            $highWaterMark = 20,
            $input = range(1, 100),
            $expectedOutput = $input
        );
    }

    public function testMapSync()
    {
        $this->_testBufferedDuplex(
            pipeline(
                mapSync(function (int $input) { return 2 * $input; }),
                buffer(['highWaterMark' => 20])
            ),
            $highWaterMark = 20,
            $input = range(1, 100),
            $expectedOutput = range(2, 200, 2)
        );
    }

    public function testThroughStream()
    {
        $this->_testBufferedDuplex(
            pipeline(
                through(),
                buffer(['highWaterMark' => 10]),
                through(),
                buffer(['highWaterMark' => 10])
            ),
            $highWaterMark = 10,
            $input = range(1, 15),
            $expectedOutput = $input
        );
    }

    public function testIteratorToStream()
    {
        $iterator = new \ArrayIterator(range(0, 9));
        $stream = readable($iterator);

        $items = [];
        $ended = false;

        $stream->on('data', function ($item) use (&$items) {
            $items[] = $item;
        });
        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        $stream->resume();

        $this->assertSame(range(0, 9), $items);
        $this->assertTrue($ended);
    }

    public function testReadableError()
    {
        $thrown = new \Exception;
        $caught = null;

        $iteratorFn = function () use ($thrown) {
            yield 1;
            throw $thrown;
        };

        $r = readable($iteratorFn());

        $r->on('error', function ($error) use (&$caught) {
            $caught = $error;
        });

        $r->on('data', function () {});

        $r->resume();

        $this->assertSame($thrown, $caught);
    }

    public function testEmptyReadablePipe()
    {
        $ended = false;

        $buffer = buffer(['highWaterMark' => 1])->pause();

        $buffer->on('end', function () use (&$ended) {
            $ended = true;
        });

        $this->eventLoop->addTimer(0, function () use ($buffer) {
            $stream = readable(new \ArrayIterator([]));
            $stream->pipe($buffer);
            $stream->resume();
        });

        $items = iterator_to_array(iterator($buffer, $this->waitFn(0.5)));

        $this->assertSame([], $items);
        $this->assertTrue($ended);
    }

    public function testStreamToIterator()
    {
        $i = 0;
        $stream = buffer();

        $waitFn = function () use ($stream, &$i) {
            if ($i < 10) {
                $stream->write($i++);
            } else {
                $stream->end();
            }
        };

        $this->assertSame(range(0, 9), iterator_to_array(iterator($stream, $waitFn)));
    }

    public function testStreamToIteratorAutoFlows()
    {
        $this->assertSame(range(0, 9), iterator_to_array(iterator(readable(range(0, 9)), function () {})));
    }

    public function testStreamToIteratorError()
    {
        $stream = buffer();
        $error = new \DomainException;

        $waitFn = function ($promise) use ($stream, $error) {
            $stream->emit('error', [$error]);
            $promise->when(function ($error) {
                throw $error;
            });
        };

        try {
            iterator($stream, $waitFn)->next();
            $this->fail();
        } catch (\DomainException $e) {
            $this->assertSame($error, $e);
        }
    }

    protected function _testBufferedDuplex(DuplexObjectStream $stream, int $highWaterMark, array $input, array $expectedOutput)
    {
        $stream->pause();

        $flushResults = [];
        $collectedData = [];

        foreach ($input as $i => $item) {
            $stream->write($item, function ($error = null, $result = null) use (&$flushResults) {
                $flushResults[] = [$error, $result];
            });
//            $this->assertSame($i < $highWaterMark - 1, $feedMore);
        }

        $stream->on('data', function ($data) use (&$collectedData) {
            $collectedData[] = $data;
        });

//        $this->assertEmpty($flushResults);
        $this->assertEmpty($collectedData);

        $stream->resume();

        $this->assertSame($expectedOutput, $collectedData);
        $this->assertCount(count($input), $flushResults);

        foreach ($input as $item) {
            $feedMore = $stream->write($item, function ($error = null, $result = null) use (&$flushResults) {
                $flushResults[] = [$error, $result];
            });
            $this->assertTrue($feedMore);
        }

        $stream->end();
    }

    public function waitFn(float $timeout)
    {
        return function ($promise) use ($timeout) {
            if (!$promise instanceof \React\Promise\PromiseInterface) {
                if (method_exists($promise, 'then')) {
                    $deferred = new \React\Promise\Deferred;
                    $promise->then([$deferred, 'resolve'], [$deferred, 'reject']);
                    $promise = $deferred->promise();
                } else {
                    return $promise;
                }
            }

            return $this->await($promise, $timeout);
        };
    }

    private function await($thenable, float $timeout = 0.1)
    {
        if (!$thenable instanceof PromiseInterface) {
            $deferred = new Deferred;
            $thenable->then([$deferred, 'resolve'], [$deferred, 'reject']);
            $thenable = $deferred->promise();
        }

        return await(timeout($thenable, $timeout, $this->eventLoop), $this->eventLoop);
    }
}
