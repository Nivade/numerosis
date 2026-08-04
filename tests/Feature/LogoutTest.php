<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Nvade\Numerosis\Tests\TestCase;

class LogoutTest extends TestCase
{
    /**
     * Test that logout route is excluded from CSRF.
     */
    public function test_logout_is_excluded_from_csrf(): void
    {
        // Making a POST request without CSRF token
        $response = $this->post('/logout');

        // It should NOT be a 419 (Page Expired)
        $this->assertNotEquals(419, $response->getStatusCode());
    }

    /**
     * Test that admin logout route is excluded from CSRF.
     */
    public function test_admin_logout_is_excluded_from_csrf(): void
    {
        // Making a POST request without CSRF token
        $response = $this->post('/admin/logout');

        // It should NOT be a 419 (Page Expired)
        $this->assertNotEquals(419, $response->getStatusCode());
    }
}
