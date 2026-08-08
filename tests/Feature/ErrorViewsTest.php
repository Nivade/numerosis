<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class ErrorViewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_it_renders_the_404_error_view(): void
    {
        Route::get('/__test-error/404', fn () => abort(404));

        $this->get('/__test-error/404')->assertNotFound()
            ->assertSee('404')
            ->assertSee('Not Found');
    }

    public function test_it_renders_the_403_error_view_with_the_exception_message(): void
    {
        Route::get('/__test-error/403', fn () => abort(403, 'You cannot do that'));

        $this->get('/__test-error/403')->assertForbidden()
            ->assertSee('403')
            ->assertSee('You cannot do that');
    }

    public function test_it_renders_the_419_error_view(): void
    {
        Route::get('/__test-error/419', fn () => abort(419));

        $this->get('/__test-error/419')
            ->assertStatus(419)
            ->assertSee('419')
            ->assertSee('Page Expired');
    }

    public function test_it_renders_the_500_error_view(): void
    {
        Route::get('/__test-error/500', fn () => abort(500));

        $this->get('/__test-error/500')->assertInternalServerError()
            ->assertSee('500')
            ->assertSee('Server Error');
    }
}
