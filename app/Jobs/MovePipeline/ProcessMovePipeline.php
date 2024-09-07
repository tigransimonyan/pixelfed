<?php

namespace App\Jobs\MovePipeline;

use App\Services\ActivityPubFetchService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use DateTime;

class ProcessMovePipeline implements ShouldQueue
{
    use Queueable;

    public $target;

    public $activity;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 6;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct($target, $activity)
    {
        $this->target = $target;
        $this->activity = $activity;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('process-move:'.$this->target),
            (new ThrottlesExceptions(2, 5 * 60))->backoff(5),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('app.env') !== 'production' || (bool) config_cache('federation.activitypub.enabled') == false) {
            throw new Exception('Activitypub not enabled');
        }

        if (! self::checkTarget()) {
            throw new Exception('Invalid target');
        }

        if (! self::checkActor()) {
            throw new Exception('Invalid actor');
        }

    }

    protected function checkTarget()
    {
        $res = ActivityPubFetchService::fetchRequest($this->target, true);

        if (! $res || ! isset($res['alsoKnownAs'])) {
            return false;
        }

        $res = Helpers::profileFetch($this->target);
        if (! $res) {
            return false;
        }

        if (is_string($res['alsoKnownAs'])) {
            return self::lowerTrim($res['alsoKnownAs']) === self::lowerTrim($this->actor);
        }

        if (is_array($res['alsoKnownAs'])) {
            $map = array_map(self::lowerTrim(), $res['alsoKnownAs']);

            return in_array($this->actor, $map);
        }

        return false;
    }

    protected function checkActor()
    {
        $res = ActivityPubFetchService::fetchRequest($this->actor, true);

        if (! $res || ! isset($res['movedTo'])) {
            return false;
        }

        $res = Helpers::profileFetch($this->actor);
        if (! $res) {
            return false;
        }

        if (is_string($res['movedTo'])) {
            return self::lowerTrim($res['movedTo']) === self::lowerTrim($this->target);
        }

        return false;
    }

    protected function lowerTrim($str)
    {
        return trim(strtolower($str));
    }
}
