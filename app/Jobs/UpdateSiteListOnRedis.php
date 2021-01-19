<?php

namespace App\Jobs;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class UpdateSiteListOnRedis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $analyticsService;

    public function __construct()
    {
        $this->analyticsService = app()->make('analytics-service');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $websiteList = Website::all();
        /*
                per ogni website predere l'id e fare una chiamata a matomo per prendere la lista dei domini
                145 sito1 sito2
            */
        Redis::connection(env('CACHE_CONNECTION'))->client()->pipeline(function ($pipe) use ($websiteList) {
            foreach ($websiteList as &$website) {
                $id = $website->analytics_id;
                $list = $this->analyticsService->getSiteUrlsFromId($id);

                $listToString = implode(' ', $list);

                $pipe->set($id, $listToString);
            }
        });

        logger()->notice(
            'Caching websites for public administrations'
        );
    }
}