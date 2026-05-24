<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wraps work in a pluggable acquire/release pair. The real call site passes
 * GET_LOCK / RELEASE_LOCK closures; tests pass boolean closures. Release is
 * only invoked when acquire succeeded, and is guaranteed via try/finally.
 */
class Fylgja_Flush_Guard {

    /** @var callable */
    private $try_lock;
    /** @var callable */
    private $release;

    public function __construct(callable $try_lock, callable $release) {
        $this->try_lock = $try_lock;
        $this->release  = $release;
    }

    /**
     * @return mixed Result of $body, or ['skipped' => 'already_running'] if lock unavailable.
     */
    public function run(callable $body) {
        if (!($this->try_lock)()) {
            return ['skipped' => 'already_running'];
        }
        try {
            return $body();
        } finally {
            ($this->release)();
        }
    }
}
