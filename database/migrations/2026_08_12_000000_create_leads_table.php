<?php

use App\Domain\LeadProcessing\Enums\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('external_reference', 100);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('company', 150)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 50)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('service_interest', 50);
            $table->unsignedInteger('company_size')->nullable();
            $table->text('message')->nullable();
            $table->boolean('consent')->default(false);
            $table->timestamp('received_at')->useCurrent();
            $table->string('normalized_email', 254)->nullable()->index();
            $table->string('normalized_phone', 32)->nullable()->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('classification', 10)->nullable();
            $table->string('assigned_to', 50)->nullable();
            $table->string('status', 20)->default(LeadStatus::Received->value);
            $table->foreignId('duplicate_of')->nullable()->constrained('leads')->restrictOnDelete();
            $table->unsignedTinyInteger('processing_attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_reference']);
            $table->index(['status', 'received_at']);
            $table->index(['classification', 'received_at']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
