<?php

declare(strict_types=1);

// The one file a host provides for the crunz engine: return the application's PSR-11 container.
// This host is Laravel, whose Application implements PSR-11; another host returns its own container.

use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__ . '/app.php';
$app->make(Kernel::class)->bootstrap();

return $app;
