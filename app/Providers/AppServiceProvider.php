<?php

namespace App\Providers;

use App\Models\Kdtph\Employee;
use App\Services\Kdtph\EmployeeDesignationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmployeeDesignationResolver::class);
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
