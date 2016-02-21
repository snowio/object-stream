<?php
namespace ObjectStream;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    public function testConcatenate()
    {
        $oddNumberGenerator = function () {
            for ($i = 0; $i < 10; $i++) {
                yield 2 * $i + 1;
            }
        };

        $evenNumberGenerator = function () {
            for ($i = 1; $i <= 10; $i++) {
                yield 2 * $i;
            }
        };

        $multiplesOfThreeGenerator = function () {
            for ($i = 1; $i <= 10; $i++) {
                yield 3 * $i;
            }
        };

        $concatenated = concatenate(
            iteratorToStream($oddNumberGenerator()),
            iteratorToStream($evenNumberGenerator()),
            iteratorToStream($multiplesOfThreeGenerator())
        );

        $data = [];
        $concatenated->on('data', function ($_data) use (&$data) {
            $data[] = $_data;
        });

        $ended = false;
        $concatenated->on('end', function ($endSubject) use ($concatenated, &$ended) {
            $this->assertSame($concatenated, $endSubject);
            $ended = true;
        });

        $this->assertEmpty($data);
        $this->assertFalse($ended);
        $concatenated->resume();
        $this->assertTrue($ended);

        $expectedData = array_merge(
            iterator_to_array($oddNumberGenerator()),
            iterator_to_array($evenNumberGenerator()),
            iterator_to_array($multiplesOfThreeGenerator())
        );
        $this->assertSame($expectedData, $data);
    }

    public function testIteratorToStream()
    {
        $oddNumberGenerator = function () {
            for ($i = 0; $i < 10; $i++) {
                yield 2 * $i + 1;
            }
        };

        $oddNumberStream = iteratorToStream($oddNumberGenerator());

        $data = [];
        $oddNumberStream->on('data', function ($_data) use (&$data) {
            $data[] = $_data;
        });

        $ended = false;
        $oddNumberStream->on('end', function ($endSubject) use ($oddNumberStream, &$ended) {
            $this->assertSame($oddNumberStream, $endSubject);
            $ended = true;
        });

        $this->assertEmpty($data);
        $this->assertFalse($ended);

        $oddNumberStream->resume();
        $expectedData = iterator_to_array($oddNumberGenerator());
        $this->assertSame($expectedData, $data);
        $this->assertTrue($ended);
    }

    public function testTransformerBasic()
    {
        $oddNumberGenerator = function () {
            for ($i = 0; $i < 10; $i++) {
                yield 2 * $i + 1;
            }
        };

        $evenNumberGenerator = function () {
            for ($i = 1; $i <= 10; $i++) {
                yield 2 * $i;
            }
        };

        $oddNumberStream = iteratorToStream($oddNumberGenerator());

        $evenNumberStream = transformer(function (int $oddNumber) {
            return $oddNumber + 1;
        });

        $oddNumberStream->pipe($evenNumberStream);

        $data = [];
        $evenNumberStream->on('data', function ($_data) use (&$data) {
            $data[] = $_data;
        });

        $ended = false;
        $evenNumberStream->on('end', function ($endSubject) use ($evenNumberStream, &$ended) {
            $this->assertSame($evenNumberStream, $endSubject);
            $ended = true;
        });

        $this->assertEmpty($data);
        $this->assertFalse($ended);

        $oddNumberStream->resume();

        $expectedData = iterator_to_array($evenNumberGenerator());
        $this->assertSame($expectedData, $data);
        $this->assertTrue($ended);
    }
}
