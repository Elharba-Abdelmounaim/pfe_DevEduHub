<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends AuthServiceProvider
{
    /**
     * Policy mappings for the application.
     * Must be a property of AuthServiceProvider, not ServiceProvider.
     */
    protected $policies = [
        \App\Models\Course::class => \App\Policies\CoursePolicy::class,
    ];

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
        // Register policies declared in $policies above
        $this->registerPolicies();

        // Enable pgcrypto so gen_random_uuid() works in migrations
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
    }
}