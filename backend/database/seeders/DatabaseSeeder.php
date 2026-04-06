<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Order matters — respect FK dependencies
            DemoSeeder::class,          // 1 teacher, 2 students, 1 course, 2 assignments
            ExtraStudentsSeeder::class, // Additional students for realistic data
        ]);
    }
}
