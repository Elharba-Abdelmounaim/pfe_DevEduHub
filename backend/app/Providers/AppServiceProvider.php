<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends AuthServiceProvider
{
    /**
     * Policy mappings for the application.
     */
    protected $policies = [
        \App\Models\Course::class     => \App\Policies\CoursePolicy::class,
        \App\Models\Assignment::class => \App\Policies\AssignmentPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register all policies declared in $policies
        $this->registerPolicies();

        // Enable pgcrypto so gen_random_uuid() works in migrations
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
    }
}
