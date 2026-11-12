<?php

use App\Models\Operation;
use Illuminate\Support\Facades\Schedule;

/*
| The panel's own housekeeping. Hosting cron jobs belong to customer accounts
| and are managed by the agent; these are only the panel's internals.
*/

// Ask the vendor for a fresh token once a day. A failure is expected on a
// private server and never stops the panel.
Schedule::command('atlas:heartbeat')->dailyAt('03:15')->withoutOverlapping();

// Expiring certificates are recalculated from the database, so the customer
// dashboard can warn before a padlock disappears.
Schedule::command('atlas:heartbeat')->hourly()->withoutOverlapping();

// Keep the local operation mirror from growing without bound; the agent keeps
// the authoritative journal forever.
Schedule::command('model:prune', ['--model' => [Operation::class]])->daily();
