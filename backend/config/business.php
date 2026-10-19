<?php

return [
    'invitation_expiry_days' => (int) env('BUSINESS_INVITATION_EXPIRY_DAYS', 7),
    'default_branch_limit' => env('BUSINESS_DEFAULT_BRANCH_LIMIT') === null ? null : (int) env('BUSINESS_DEFAULT_BRANCH_LIMIT'),
    'branch_limits' => [
        'trial' => env('BUSINESS_TRIAL_BRANCH_LIMIT', 4) === null ? null : (int) env('BUSINESS_TRIAL_BRANCH_LIMIT', 4),
        'active' => env('BUSINESS_ACTIVE_BRANCH_LIMIT') === null ? null : (int) env('BUSINESS_ACTIVE_BRANCH_LIMIT'),
        'past_due' => env('BUSINESS_PAST_DUE_BRANCH_LIMIT', 0) === null ? null : (int) env('BUSINESS_PAST_DUE_BRANCH_LIMIT', 0),
        'cancelled' => 0,
    ],
    'dashboard_widget_keys' => [
        'sales_overview' => 'Sales Overview', 'total_products' => 'Total Products', 'stock_summary' => 'Stock Summary',
        'low_stock' => 'Low Stock', 'recent_transactions' => 'Recent Transactions', 'staff_overview' => 'Staff Overview',
        'daily_revenue' => 'Daily Revenue', 'weekly_revenue' => 'Weekly Revenue', 'monthly_revenue' => 'Monthly Revenue',
        'branch_performance' => 'Branch Performance', 'payment_summary' => 'Payment Summary', 'supplier_overview' => 'Supplier Overview', 'expenses' => 'Expenses',
    ],
    'invitation_expiry_hours' => (int) env('BUSINESS_INVITATION_EXPIRY_HOURS', 24),
    'email_verification_expiry_minutes' => (int) env('EMAIL_VERIFICATION_EXPIRY_MINUTES', 60),
    'require_super_admin_approval' => (bool) env('BUSINESS_REQUIRE_SUPER_ADMIN_APPROVAL', false),
];
