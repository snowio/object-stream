<?php
namespace ObjectStream\Promise;

use ObjectStream\Promise;
use ObjectStream\ReadableObjectStream;
use ObjectStream\WritableObjectStream;
use function ObjectStream\__fulfilledPromise;
use function ObjectStream\__promise;

function toArray(ReadableObjectStream $stream) : Promise
{
    $promise = __promise($graceful = true);
    \ObjectStream\toArray($stream, [$promise, 'resolve']);

    return $promise;
}

function read(ReadableObjectStream $stream) : Promise
{
    if ([] !== $items = $stream->read(1)) {
        return __fulfilledPromise($items[0]);
    }

    $dataPromise = __promise($graceful = true);

    $stream->on('readable', $readableListener = function () use ($stream, $dataPromise) {
        if ([] !== $items = $stream->read(1)) {
            $dataPromise->succeed($items[0]);
        }
    });
    $stream->on('end', [$dataPromise, 'resolve']);
    $stream->on('error', [$dataPromise, 'resolve']);

    $dataPromise->when(function () use ($stream, $dataPromise, $readableListener) {
        $stream->removeListener('readable', $readableListener);
        $stream->removeListener('end', [$dataPromise, 'resolve']);
        $stream->removeListener('error', [$dataPromise, 'resolve']);
    });

    return $dataPromise;
}

function whenEnded(ReadableObjectStream $stream) : Promise
{
    $promise = __promise($graceful = true);

    $stream->on('end', [$promise, 'resolve']);
    $stream->on('error', [$promise, 'resolve']);

    $promise->when(function () use ($stream, $promise) {
        $stream->removeListener('end', [$promise, 'resolve']);
        $stream->removeListener('error', [$promise, 'resolve']);
    });

    return $promise;
}

function whenFinished(WritableObjectStream $stream) : Promise
{
    $promise = __promise($graceful = true);

    $stream->on('finish', [$promise, 'resolve']);
    $stream->on('error', [$promise, 'resolve']);

    $promise->when(function () use ($stream, $promise) {
        $stream->removeListener('finish', [$promise, 'resolve']);
        $stream->removeListener('error', [$promise, 'resolve']);
    });

    return $promise;
}
