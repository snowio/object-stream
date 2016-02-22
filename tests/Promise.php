<?php
namespace ObjectStream\Test;

class Promise
{
    private $isResolved = false;
    private $error;
    private $result;
    private $observers = [];

    public static function resolver(callable $callback = null, $cbData = null) : callable
    {
        $promise = new self;

        if (null !== $callback) {
            $promise->when($callback, $cbData);
        }

        return [$promise, 'resolve'];
    }

    public function isResolved() : bool
    {
        return $this->isResolved;
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

    public function resolve($error = null, $result = null) : self
    {
        if ($this->isResolved) {
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
}
