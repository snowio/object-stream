<?php
namespace ObjectStream;

interface Promise
{
    public function then(callable $onSuccess = null, callable $onFailure = null);

    public function when(callable $callback, ...$args) : self;

    public function succeed($result = null) : self;

    public function fail(\Throwable $error) : self;

    public function resolve(\Throwable $error = null, $result = null) : self;
}
