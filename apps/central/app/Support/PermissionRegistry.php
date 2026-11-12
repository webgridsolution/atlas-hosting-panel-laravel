<?php

namespace App\Support;

/**
 * Single source of truth for permissions.
 * Seeders read it, CI asserts the DB matches it, and views gate on the same keys.
 */
final class PermissionRegistry
{
    public const PERMISSIONS = [
        // Customers & accounts
        'customers.view',
        'customers.manage',
        'customers.impersonate',

        // Catalogue & pricing
        'plans.view',
        'plans.manage',
        'prices.manage',
        'coupons.manage',

        // Billing
        'orders.view',
        'payments.view',
        'payments.refund',
        'invoices.view',
        'invoices.void',
        'subscriptions.cancel',

        // Licensing
        'licenses.view',
        'licenses.issue',
        'licenses.suspend',
        'licenses.revoke',
        'licenses.transfer',
        'licenses.override_fingerprint',

        // Infrastructure
        'servers.view',
        'servers.deregister',
        'releases.view',
        'releases.publish',
        'releases.sign',

        // Support & content
        'tickets.view',
        'tickets.reply',
        'tickets.assign',
        'content.manage',
        'advisories.publish',

        // Control plane
        'settings.view',
        'settings.manage',
        'staff.manage',
        'audit.view',
        'security.view',

        // Customer-side capability
        'portal.access',
    ];

    public const ROLES = [
        'super_admin' => self::PERMISSIONS,
        'admin' => [
            'customers.view', 'customers.manage', 'plans.view', 'plans.manage', 'prices.manage',
            'coupons.manage', 'orders.view', 'payments.view', 'payments.refund', 'invoices.view',
            'subscriptions.cancel', 'licenses.view', 'licenses.issue', 'licenses.suspend',
            'licenses.transfer', 'licenses.override_fingerprint', 'servers.view', 'servers.deregister',
            'releases.view', 'tickets.view', 'tickets.reply', 'tickets.assign', 'content.manage',
            'settings.view', 'audit.view', 'security.view',
        ],
        'finance' => [
            'customers.view', 'plans.view', 'prices.manage', 'coupons.manage', 'orders.view',
            'payments.view', 'payments.refund', 'invoices.view', 'invoices.void',
            'subscriptions.cancel', 'licenses.view', 'licenses.issue', 'audit.view',
        ],
        'support' => [
            'customers.view', 'licenses.view', 'licenses.issue', 'licenses.transfer',
            'servers.view', 'tickets.view', 'tickets.reply', 'tickets.assign',
        ],
        'content_editor' => ['content.manage', 'advisories.publish', 'tickets.view'],
        'customer' => ['portal.access'],
    ];
}
