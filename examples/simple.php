<?php

declare(strict_types=1);

use Amp\Loop;
use JustBlackBird\AmpPriorityEmitter\Emitter;

// The example below will output:
// - important message
// - message one
// - message two

Loop::run(static function () {
    /** @var Emitter<string> $emitter */
    $emitter = new Emitter();

    $emitter->emit('message one', 0);
    $emitter->emit('message two', 0);
    $emitter->emit('important message', 5);
    $emitter->complete();

    $iterator = $emitter->iterate();
    while (yield $iterator->advance()) {
        echo "- " . $iterator->getCurrent() . "\n";
    }
});
