<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Only Feature tests boot Laravel; unit tests prove Domain, Application and the framework-free adapters don't need it.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
