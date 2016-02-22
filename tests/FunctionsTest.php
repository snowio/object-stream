<?php
namespace ObjectStream\Test;

use ObjectStream\DuplexObjectStream;
use function ObjectStream\pipeline;
use function ObjectStream\buffer;
use function ObjectStream\mapSync;
use function ObjectStream\through;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    public function testBuffer()
    {
        $this->_testBufferedDuplex(
            buffer($highWaterMark = 20),
            $highWaterMark,
            $input = range(1, 100),
            $expectedOutput = $input
        );
    }

    public function testMapSync()
    {
        $this->_testBufferedDuplex(
            pipeline(
                mapSync(function (int $input) { return 2 * $input; }),
                buffer($highWaterMark = 20)
            ),
            $highWaterMark,
            $input = range(1, 100),
            $expectedOutput = range(2, 200, 2)
        );
    }

    public function testThroughStream()
    {
        $this->_testBufferedDuplex(
            pipeline(
                through(),
                buffer($highWaterMark = 10),
                through(),
                buffer($highWaterMark = 10)
            ),
            $highWaterMark,
            $input = range(1, 15),
            $expectedOutput = $input
        );
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
