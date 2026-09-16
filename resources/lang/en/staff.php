<?php

declare(strict_types=1);

return [
    'title' => 'Staff',

    'nav' => [
        'tenants' => 'Tenants',
        'provisions' => 'Provisions',
        'queue' => 'Queue',
        'subscriptions' => 'Subscriptions',
        'users' => 'Users',
        'leave' => 'Back to app',
    ],

    'tenants' => [
        'heading' => 'Tenants',
        'subheading' => 'Every tenant on this installation',
        'search' => 'Search name, slug or domain',
        'empty' => 'No tenants match this filter.',
        'columns' => [
            'tenant' => 'Tenant',
            'owner' => 'Owner',
            'plan' => 'Plan',
            'status' => 'Status',
            'provisioned' => 'Provisioned',
        ],
    ],

    'filters' => [
        'all' => 'All',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
        'failed' => 'Failed',
    ],

    'status' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
        'provisioning' => 'Provisioning',
    ],

    'tenant' => [
        'members' => 'Members',
        'domains' => 'Domains',
        'subscription' => 'Subscription',
        'provision' => 'Provision',
        'tenant_users' => 'Tenant-side users',
        'no_members' => 'This tenant has no members.',
        'no_domains' => 'This tenant has no domains.',
        'no_subscription' => 'This tenant has no subscription.',
        'no_provision' => 'This tenant has no provision record.',
        'purges_at' => 'Purges at :date',
    ],

    'actions' => [
        'suspend' => 'Suspend',
        'restore' => 'Restore',
        'reopen' => 'Reopen',
        'reassign' => 'Make owner',
        'impersonate' => 'Sign in as',
        'retry' => 'Retry',
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
        'never_mind' => 'Never mind',
        'open_in_stripe' => 'Open in Stripe',
    ],

    'confirm' => [
        'suspend' => 'Suspend :tenant? Its members lose access until it is restored.',
        'restore' => 'Restore access to :tenant?',
        'reopen' => 'Reopen :tenant? Subscriptions still on their grace period resume.',
        'reassign' => 'Make :user the owner of :tenant?',
        'cancel_provision' => 'Cancel provisioning for :slug? The reservation is released.',
    ],

    'logged' => [
        'suspended' => 'Tenant :tenant suspended by staff',
        'restored' => 'Tenant :tenant restored by staff',
        'reopened' => 'Tenant :tenant reopened by staff',
        'reassigned' => 'Ownership of :tenant reassigned to :user by staff',
        'provision_retried' => 'Provisioning for :slug retried by staff',
        'provision_cancelled' => 'Provisioning for :slug cancelled by staff',
    ],

    'provisions' => [
        'heading' => 'Provisions',
        'subheading' => 'Every tenant this installation has built, and what each step did',
        'empty' => 'No provision records.',
        'columns' => [
            'slug' => 'Slug',
            'status' => 'Status',
            'steps' => 'Steps',
            'attempts' => 'Attempts',
            'started' => 'Started',
            'error' => 'Error',
        ],
    ],

    'provision' => [
        'back' => 'Back to provisions',
        'timeline' => 'Steps',
        'completed' => 'Completed',
        'pending' => 'pending',
        'columns' => [
            'step' => 'Step',
            'state' => 'State',
            'duration' => 'Duration',
            'at' => 'Recorded',
        ],
    ],

    'queue' => [
        'heading' => 'Queue',
        'subheading' => 'Depth per queue, and what the last hour did to provisioning',
        'health' => 'Health',
        'unknown' => 'unknown',
        'never' => 'never',
        'failed_jobs' => 'Failed jobs',
        'scheduler' => 'Scheduler last ran',
        'failed_provisions' => 'Provisions failed in the last hour',
        'stalled_provisions' => 'Stalled provisions',
        'columns' => [
            'queue' => 'Queue',
            'depth' => 'Depth',
            'oldest' => 'Oldest job',
        ],
    ],

    'subscriptions' => [
        'heading' => 'Subscriptions',
        'subheading' => 'Read-only. Billing itself is managed in Stripe.',
        'empty' => 'No subscriptions.',
        'columns' => [
            'tenant' => 'Tenant',
            'plan' => 'Plan',
            'status' => 'Status',
            'ends' => 'Ends',
        ],
    ],

    'users' => [
        'heading' => 'Users',
        'subheading' => 'Central accounts and the tenants they belong to',
        'search' => 'Search name or email',
        'empty' => 'No users match this filter.',
        'columns' => [
            'user' => 'User',
            'email' => 'Email',
            'tenants' => 'Tenants',
            'joined' => 'Joined',
        ],
    ],
];
