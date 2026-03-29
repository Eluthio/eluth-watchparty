<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (!Schema::hasTable('watch_proposals')) {
    Schema::create('watch_proposals', function (Blueprint $table) {
        $table->id();
        $table->string('channel_id')->index();
        $table->string('url', 2048);
        $table->string('title', 512)->nullable();
        $table->string('proposed_by', 64);
        $table->string('proposed_by_id');
        $table->timestamps();
    });
}

if (!Schema::hasTable('watch_votes')) {
    Schema::create('watch_votes', function (Blueprint $table) {
        $table->unsignedBigInteger('proposal_id');
        $table->string('voter_id');
        $table->primary(['proposal_id', 'voter_id']);
        $table->foreign('proposal_id')->references('id')->on('watch_proposals')->cascadeOnDelete();
    });
}

if (!Schema::hasTable('watch_approvals')) {
    Schema::create('watch_approvals', function (Blueprint $table) {
        $table->unsignedBigInteger('proposal_id')->primary();
        $table->string('approved_by', 64);
        $table->timestamp('approved_at')->nullable();
        $table->foreign('proposal_id')->references('id')->on('watch_proposals')->cascadeOnDelete();
    });
}

if (!Schema::hasTable('watch_sessions')) {
    Schema::create('watch_sessions', function (Blueprint $table) {
        $table->id();
        $table->string('channel_id')->unique();
        $table->unsignedBigInteger('proposal_id')->nullable();
        $table->text('url')->nullable();
        $table->string('title', 512)->nullable();
        $table->enum('state', ['idle', 'synchronising', 'playback', 'paused'])->default('idle');
        $table->decimal('timecode', 10, 3)->default(0);
        $table->timestamp('timecode_at')->nullable();
        $table->timestamp('sync_at')->nullable();
        $table->string('controlled_by', 64)->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}
