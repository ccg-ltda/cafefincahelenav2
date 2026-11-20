<?php

namespace App\Jobs;

use App\Services\OrderNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one email. $tries and backoff() belong to the job and WIN over `queue:work --tries=1`: the worker reads the job's own
 * maxTries()/backoff() first. A failure never touches the order: it is stored in the notification row and retried later.
 */
class SendOrderNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $notificationId, public readonly bool $force = false) {}

    /** @return list<int> seconds before attempts 2, 3, 4 and 5 */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(OrderNotificationService $service): void
    {
        $service->deliver($this->notificationId, $this->force);
    }

    public function failed(Throwable $exception): void
    {
        app(OrderNotificationService::class)->markFailed($this->notificationId, $exception);
    }
}
