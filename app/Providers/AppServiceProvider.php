<?php

namespace App\Providers;

use App\Models\Kdtph\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Request::macro('employee', function (): ?Employee {
            /** @var Request $this */
            return $this->attributes->get('employee');
        });
    }
}
