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
use function React\Promise\Timer\timeout;
use function Clue\React\Block\await;
use function ObjectStream\map;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    /** @var LoopInterface */
    private $eventLoop;

    public function setUp()
    {
        $this->eventLoop = Factory::create();
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
            $feedMore = $stream->write($item, Promise::resolver(function ($error = null, $result = null) use (&$flushResults) {
                $flushResults[] = [$error, $result];
            }));
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
            $feedMore = $stream->write($item, Promise::resolver(function ($error = null, $result = null) use (&$flushResults) {
                $flushResults[] = [$error, $result];
            }));
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

            return await(timeout($promise, $timeout, $this->eventLoop), $this->eventLoop);
        };
    }
}
