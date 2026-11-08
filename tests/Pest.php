<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/geography.php';
require_once __DIR__.'/Support/order.php';
require_once __DIR__.'/Support/pricing.php';
require_once __DIR__.'/Support/reporting.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)->in('Arch');
