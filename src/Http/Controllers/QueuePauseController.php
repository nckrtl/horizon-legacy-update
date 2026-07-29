<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Validation\Rule;
use Laravel\Horizon\Horizon;

class QueuePauseController extends Controller
{
    /**
     * Pause the given queue.
     *
     * When duration_minutes is supplied and timed pausing is supported, Laravel's
     * pauseFor() is used so the framework cache TTL is authoritative. Otherwise
     * the queue is paused indefinitely via pause().
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Queue\QueueManager  $queues
     * @param  string  $connection
     * @param  string  $queue
     * @return array
     */
    public function store(Request $request, QueueManager $queues, $connection, $queue)
    {
        $this->ensureQueuePausingIsSupported();
        $this->validateQueue($request, $connection, $queue);

        $duration = $this->durationMinutes($request);

        if ($duration !== null
            && Horizon::supportsTimedQueuePausing()
            && method_exists($queues, 'pauseFor')) {
            $queues->pauseFor($connection, $queue, now()->addMinutes($duration));
        } else {
            $queues->pause($connection, $queue);
        }

        return [
            'connection' => $connection,
            'queue' => $queue,
            'paused' => $queues->isPaused($connection, $queue),
        ];
    }

    /**
     * Resume the given queue.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Queue\QueueManager  $queues
     * @param  string  $connection
     * @param  string  $queue
     * @return array
     */
    public function destroy(Request $request, QueueManager $queues, $connection, $queue)
    {
        $this->ensureQueuePausingIsSupported();
        $this->validateQueue($request, $connection, $queue);

        $queues->resume($connection, $queue);

        return [
            'connection' => $connection,
            'queue' => $queue,
            'paused' => $queues->isPaused($connection, $queue),
        ];
    }

    /**
     * Abort when queue pausing is not supported at runtime.
     *
     * Routes are always registered so route caches stay stable across environments.
     * This guard is authoritative: unsupported Laravel versions and disabled worker
     * pause polling return 404 before unavailable pause APIs are called.
     *
     * @return void
     */
    protected function ensureQueuePausingIsSupported()
    {
        abort_unless(Horizon::supportsQueuePausing(), 404);
    }

    /**
     * Validate a queue control request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $connection
     * @param  string  $queue
     * @return void
     */
    protected function validateQueue(Request $request, $connection, $queue)
    {
        $request->merge([
            'connection' => $connection,
            'queue' => $queue,
        ]);

        $request->validate([
            'connection' => ['required', 'string', 'max:255', Rule::in(array_keys(config('queue.connections', [])))],
            'queue' => ['required', 'string', 'max:255'],
        ]);
    }

    /**
     * Validate and return an optional pause duration in minutes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|null
     */
    protected function durationMinutes(Request $request)
    {
        if (! $request->exists('duration_minutes') || $request->input('duration_minutes') === null) {
            return null;
        }

        $request->validate([
            'duration_minutes' => ['integer', 'min:1', 'max:525600'],
        ]);

        return (int) $request->input('duration_minutes');
    }
}
