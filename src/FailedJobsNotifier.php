<?php

namespace Okipa\LaravelFailedJobsNotifier;

use Carbon\Carbon;
use DB;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Okipa\LaravelFailedJobsNotifier\Exceptions\InexistentFailedJobsTable;
use Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidDaysLimit;
use Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidNotification;
use Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidProcessAllowedToRun;

class FailedJobsNotifier
{
    /**
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InexistentFailedJobsTable
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidDaysLimit
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidNotification
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidProcessAllowedToRun
     */
    public function notify(): void
    {
        if ($this->processIsAllowedToRun()) {
            $stuckFailedJobs = $this->getStuckFailedJobs();
            if ($stuckFailedJobs->isNotEmpty()) {
                $notifiable = app(config('failed-jobs-notifier.notifiable'));
                $notification = $this->getNotification($stuckFailedJobs);
                $notifiable->notify($notification);
            }
        }
    }

    /**
     * @return bool
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidProcessAllowedToRun
     */
    public function processIsAllowedToRun(): bool
    {
        $processAllowedToRun = config('failed-jobs-notifier.processAllowedToRun');
        if (is_callable($processAllowedToRun)) {
            return $processAllowedToRun();
        } elseif (is_bool($processAllowedToRun)) {
            return $processAllowedToRun;
        }
        throw new InvalidProcessAllowedToRun('The processAllowedToRun config value is not a boolean or a callable.');
    }

    /**
     * @return \Illuminate\Support\Collection
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InexistentFailedJobsTable
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidDaysLimit
     */
    public function getStuckFailedJobs(): Collection
    {
        $this->checkFailedJobsTableExists();
        $daysLimit = $this->getDaysLimit();
        $dateLimit = Carbon::now()->subDays($daysLimit)->endOfDay();

        return DB::table('failed_jobs')->where('failed_at', '<=', $dateLimit)->get();
    }

    /**
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InexistentFailedJobsTable
     */
    public function checkFailedJobsTableExists(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            throw new InexistentFailedJobsTable('No failed_jobs table has been found. Please check Laravel '
                . 'documentation to set it up : https://laravel.com/docs/queues#dealing-with-failed-jobs.');
        }
    }

    /**
     * @return int
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidDaysLimit
     */
    public function getDaysLimit(): int
    {
        $daysLimit = config('failed-jobs-notifier.daysLimit');
        if (! is_int($daysLimit)) {
            throw new InvalidDaysLimit('The configured day limit is not an integer.');
        }

        return $daysLimit;
    }

    /**
     * @param \Illuminate\Support\Collection $stuckFailedJobs
     *
     * @return \Illuminate\Notifications\Notification
     * @throws \Okipa\LaravelFailedJobsNotifier\Exceptions\InvalidNotification
     */
    public function getNotification(Collection $stuckFailedJobs): Notification
    {
        $notification = app(config('failed-jobs-notifier.notification'), ['stuckFailedJobs' => $stuckFailedJobs]);
        if (! $notification instanceof Notification || ! is_subclass_of($notification, Notification::class)) {
            throw new InvalidNotification('The configured notification does not extend ' . Notification::class);
        }

        return $notification;
    }
}
