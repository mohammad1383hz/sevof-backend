<?php

use App\Console\Commands\RunAutopilotAgents;
use Illuminate\Support\Facades\Schedule;

Schedule::command(RunAutopilotAgents::class)
    ->everyMinute()
    ->withoutOverlapping(5)   // حداکثر ۵ دقیقه قفل
    ->runInBackground();
Schedule::command('products:refresh --queue')
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30);
Schedule::command('agent:keyword-research --stagger=30')
    ->dailyAt('03:00')
    ->withoutOverlapping();
// اول داده‌ی سرچ کنسول تازه می‌شود، بعد اتوماسیون از روی داده‌ی تازه مقاله‌ها را به‌روز می‌کند
Schedule::command('search-console:sync-all')
    ->dailyAt('04:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30);
Schedule::command('articles:auto-refresh')
    ->dailyAt('07:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30);
// Expire subscriptions that hit their plan's article cap (× 1/3/6 months) and turn off their Agent
Schedule::command('subscriptions:enforce-article-limits')
    ->dailyAt('00:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30);
// وضعیت ایندکس مقاله‌های تولیدشده در گوگل (فقط برای پنل ادمین)
Schedule::command('search-console:check-indexing')
    ->dailyAt('05:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(30);
