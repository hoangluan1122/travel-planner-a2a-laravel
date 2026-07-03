<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:travel-planner', function (): void {
    $this->info('Travel Planner A2A Laravel migration');
});
