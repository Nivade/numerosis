<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Nvade\Numerosis\Tests\TestCase;

class StaticPagesTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_static_pages_are_accessible(): void
    {
        $pages = ['terms', 'privacy', 'about', 'features'];

        foreach ($pages as $page) {
            $response = $this->get(route($page));
            if ($response->status() !== 200) {
                dump("Failed page: {$page}");
                dump($response->getContent());
            }
            $response->assertStatus(200);
        }
    }
}
