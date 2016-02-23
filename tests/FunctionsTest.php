<?php
namespace ObjectStream\Test;

use ObjectStream\DuplexObjectStream;
use function ObjectStream\pipeline;
use function ObjectStream\buffer;
use function ObjectStream\mapSync;
use function ObjectStream\through;
use function ObjectStream\iterator;
use function ObjectStream\readable;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
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
    }
}
