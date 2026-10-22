<?php

use Tests\Support\BillingTestHelpers;
use Tests\Support\OpenApiAssertions;
use Tests\Support\PlatformTestHelpers;
use Tests\Support\ResetsTenancyDatabase;
use Tests\Support\SignupTestHelpers;
use Tests\Support\TenantAuthTestHelpers;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(ResetsTenancyDatabase::class)->in('Feature/Tenancy', 'Feature/Platform', 'Feature/Plans', 'Feature/Signup', 'Feature/Billing', 'Feature/TenantAuth');
pest()->use(TenantAuthTestHelpers::class)->in('Feature/TenantAuth', 'Feature/Billing');
pest()->use(BillingTestHelpers::class, OpenApiAssertions::class)->in('Feature/Billing', 'Feature/TenantAuth');
pest()->use(SignupTestHelpers::class)->in('Feature/Signup');
pest()->use(PlatformTestHelpers::class)->in('Feature/Platform', 'Feature/Plans');

require_once __DIR__.'/Support/SubscriptionManagementHelpers.php';
