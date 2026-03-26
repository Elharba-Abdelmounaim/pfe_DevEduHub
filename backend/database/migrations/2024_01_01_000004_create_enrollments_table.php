<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->foreignUuid('student_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->foreignUuid('course_id')
                  ->constrained('courses')
                  ->cascadeOnDelete();

            $table->timestamp('enrollment_date')->useCurrent();

            // 'active' | 'dropped' | 'completed'
            $table->string('status')->default('active');

            // Filled at end of semester
            $table->decimal('final_grade', 5, 2)->nullable();

            // One enrollment per student per course
            $table->unique(['student_id', 'course_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
