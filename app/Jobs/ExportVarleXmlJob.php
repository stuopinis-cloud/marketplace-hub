<?php

namespace App\Jobs;

use App\Services\Marketplace\Varle\VarleFeedPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportVarleXmlJob implements ShouldQueue
{
    use Queueable;

    public function handle(VarleFeedPublisher $publisher): void
    {
        $publisher->publish();
    }
}
