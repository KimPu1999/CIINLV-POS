<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('employee_number');
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('employment_type')->default('full_time');
            $table->date('hire_date')->nullable();
            $table->string('employment_status')->default('active');
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'employee_number']);
            $table->index(['tenant_id', 'employment_status']);
        });

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'employee_profile_id']);
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->timestampTz('clock_in')->nullable();
            $table->timestampTz('clock_out')->nullable();
            $table->string('status')->default('present');
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['employee_profile_id', 'work_date']);
            $table->index(['tenant_id', 'work_date', 'status']);
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('leave_type');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'starts_on']);
        });

        Schema::create('employee_payroll_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('base_salary', 14, 2)->default(0);
            $table->decimal('allowances', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('net_salary', 14, 2)->default(0);
            $table->string('currency_code', 3)->default('MMK');
            $table->string('status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['employee_profile_id', 'period_start', 'period_end']);
            $table->index(['tenant_id', 'status', 'period_end']);
        });

        Schema::create('employee_commissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('base_amount', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->string('status')->default('accrued');
            $table->string('source_reference')->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'employee_profile_id', 'status']);
            $table->unique(['tenant_id', 'source_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_commissions');
        Schema::dropIfExists('employee_payroll_records');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employee_profiles');
    }
};
