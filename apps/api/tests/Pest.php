<?php

use Tests\Support\PlatformTestHelpers;
use Tests\Support\ResetsTenancyDatabase;
use Tests\Support\SignupTestHelpers;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(ResetsTenancyDatabase::class)->in('Feature/Tenancy', 'Feature/Platform', 'Feature/Plans', 'Feature/Signup');
pest()->use(SignupTestHelpers::class)->in('Feature/Signup');
pest()->use(PlatformTestHelpers::class)->in('Feature/Platform', 'Feature/Plans');
