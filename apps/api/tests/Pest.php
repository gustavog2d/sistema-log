<?php

use Tests\Support\BillingTestHelpers;
use Tests\Support\OpenApiAssertions;
use Tests\Support\PlatformTestHelpers;
use Tests\Support\ResetsTenancyDatabase;
use Tests\Support\SignupTestHelpers;
use Tests\Support\TenantAuthTestHelpers;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(ResetsTenancyDatabase::class)->in('Feature/Tenancy', 'Feature/Platform', 'Feature/Plans', 'Feature/Signup', 'Feature/Billing', 'Feature/TenantAuth', 'Feature/Team', 'Feature/Coupons', 'Feature/Notifications', 'Feature/Admin', 'Feature/Leads', 'Feature/Audit');
pest()->use(TenantAuthTestHelpers::class)->in('Feature/TenantAuth', 'Feature/Billing', 'Feature/Team', 'Feature/Notifications', 'Feature/Audit');
pest()->use(BillingTestHelpers::class, OpenApiAssertions::class)->in('Feature/Billing', 'Feature/TenantAuth', 'Feature/Team', 'Feature/Coupons', 'Feature/Notifications', 'Feature/Admin', 'Feature/Leads', 'Feature/Audit');
pest()->use(SignupTestHelpers::class)->in('Feature/Signup', 'Feature/Coupons', 'Feature/Notifications');
pest()->use(PlatformTestHelpers::class)->in('Feature/Platform', 'Feature/Plans', 'Feature/Coupons', 'Feature/Admin', 'Feature/Leads');

require_once __DIR__.'/Support/SubscriptionManagementHelpers.php';
require_once __DIR__.'/Support/TeamHelpers.php';
require_once __DIR__.'/Support/CouponHelpers.php';
require_once __DIR__.'/Support/NotificationHelpers.php';
require_once __DIR__.'/Support/AdminHelpers.php';
require_once __DIR__.'/Support/LeadHelpers.php';
require_once __DIR__.'/Support/PlanChangeHelpers.php';
require_once __DIR__.'/Support/AuditProbe.php';
require_once __DIR__.'/Support/AuditHelpers.php';
