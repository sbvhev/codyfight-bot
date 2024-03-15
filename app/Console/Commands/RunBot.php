<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\CodyBot;

class RunBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:run-bot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        CodyBot::dispatchSync();
    }
}
