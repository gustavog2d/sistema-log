<?php

use Tests\Support\BillingTestHelpers;
use Tests\Support\OpenApiAssertions;
use Tests\Support\PlatformTestHelpers;
use Tests\Support\ResetsTenancyDatabase;
use Tests\Support\SignupTestHelpers;
use Tests\Support\TenantAuthTestHelpers;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(ResetsTenancyDatabase::class)->in('Feature/Tenancy', 'Feature/Platform', 'Feature/Plans', 'Feature/Signup', 'Feature/Billing', 'Feature/TenantAuth', 'Feature/Team', 'Feature/Coupons');
pest()->use(TenantAuthTestHelpers::class)->in('Feature/TenantAuth', 'Feature/Billing', 'Feature/Team');
pest()->use(BillingTestHelpers::class, OpenApiAssertions::class)->in('Feature/Billing', 'Feature/TenantAuth', 'Feature/Team', 'Feature/Coupons');
pest()->use(SignupTestHelpers::class)->in('Feature/Signup', 'Feature/Coupons');
pest()->use(PlatformTestHelpers::class)->in('Feature/Platform', 'Feature/Plans', 'Feature/Coupons');

require_once __DIR__.'/Support/SubscriptionManagementHelpers.php';
require_once __DIR__.'/Support/TeamHelpers.php';
require_once __DIR__.'/Support/CouponHelpers.php';
