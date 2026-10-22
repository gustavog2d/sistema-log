<?php

use Tests\Support\PlatformTestHelpers;
use Tests\Support\ResetsTenancyDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(ResetsTenancyDatabase::class)->in('Feature/Tenancy', 'Feature/Platform');
pest()->use(PlatformTestHelpers::class)->in('Feature/Platform');
