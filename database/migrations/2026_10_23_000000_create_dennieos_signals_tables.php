<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('dennieos-signals.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::create('dennieos_error_events', function (Blueprint $table): void {
            $table->bigIncrements('id'); // the pull cursor
            $table->string('event_id', 64)->unique(); // stable id, sent as the contract's event id
            $table->dateTime('occurred_at', 3);
            $table->string('level', 16);
            $table->string('class', 255);
            $table->text('message');
            $table->mediumText('stack')->nullable();
            $table->string('fingerprint_hint', 128)->nullable();
            $table->text('context');
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('dennieos_log_cursors', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('path', 255)->unique();
            $table->unsignedBigInteger('inode')->nullable();
            $table->unsignedBigInteger('offset')->default(0);
            $table->string('first_line_hash', 64)->nullable();
            $table->string('tail_hash', 64)->nullable(); // the bytes just before the offset: detects a rewrite in place
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('dennieos_state', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dennieos_state');
        Schema::dropIfExists('dennieos_log_cursors');
        Schema::dropIfExists('dennieos_error_events');
    }
};
