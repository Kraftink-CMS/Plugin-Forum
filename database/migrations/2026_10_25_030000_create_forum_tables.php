<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createBaseTables();
        $this->createRelatedTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('forum__announcements');
        Schema::dropIfExists('forum__drafts');
        Schema::dropIfExists('forum__attachments');
        Schema::dropIfExists('forum__mentions');
        Schema::dropIfExists('forum__poll_revisions');
        Schema::dropIfExists('forum__subscriptions');
        Schema::dropIfExists('forum__profiles');
        Schema::dropIfExists('forum__category_tags');
        Schema::dropIfExists('forum__tag_roles');
        Schema::dropIfExists('forum__category_roles');
        Schema::dropIfExists('forum__poll_votes');
        Schema::dropIfExists('forum__poll_ballots');
        Schema::dropIfExists('forum__poll_options');
        Schema::dropIfExists('forum__polls');
        Schema::dropIfExists('forum__post_likes');
        Schema::dropIfExists('forum__topic_tags');
        Schema::dropIfExists('forum__tags');
        Schema::dropIfExists('forum__reports');
        Schema::dropIfExists('forum__post_revisions');
        Schema::dropIfExists('forum__posts');
        Schema::dropIfExists('forum__topics');
        Schema::dropIfExists('forum__categories');
    }

    private function createBaseTables(): void
    {
        Schema::create('forum__categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->index()->constrained('forum__categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('locale', 12);
            $table->string('icon', 60)->default('chat-left-text');
            $table->string('read_access', 16)->default('public');
            $table->string('read_permission', 100)->nullable();
            $table->string('write_access', 16)->default('members');
            $table->string('write_permission', 100)->nullable();
            $table->boolean('locked')->default(false);
            $table->boolean('private_topics')->default(false);
            $table->unsignedInteger('position')->default(0);
        });
        Schema::create('forum__topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('forum__categories')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 180);
            $table->boolean('pinned')->default(false);
            $table->boolean('locked')->default(false);
            $table->boolean('hidden')->default(false);
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['category_id', 'hidden', 'pinned', 'updated_at']);
            $table->index(['deleted_at', 'id'], 'forum_topics_trash_index');
        });
        Schema::create('forum__posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum__topics')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->boolean('hidden')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['topic_id', 'hidden', 'id']);
            $table->index(['deleted_at', 'id'], 'forum_posts_trash_index');
        });
        Schema::create('forum__post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum__posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->boolean('anonymized')->default(false);
            $table->timestamp('created_at');
            $table->index(['post_id', 'created_at', 'id'], 'forum_post_revisions_retention_index');
        });
        Schema::create('forum__reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum__posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->boolean('resolved')->default(false);
            $table->timestamp('created_at');
            $table->unique(['post_id', 'user_id']);
        });
        Schema::create('forum__tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->string('color', 7)->default('#34594b');
        });
        Schema::create('forum__topic_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum__topics')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('forum__tags')->cascadeOnDelete();
            $table->unique(['topic_id', 'tag_id']);
        });
        Schema::create('forum__post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum__posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['post_id', 'user_id']);
        });
        Schema::create('forum__polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->unique()->constrained('forum__topics')->cascadeOnDelete();
            $table->string('question', 200);
            $table->unsignedTinyInteger('max_choices')->default(1);
            $table->boolean('allow_change_votes')->default(false);
            $table->boolean('allow_withdraw_votes')->default(false);
            $table->string('results_visibility', 16)->default('always');
            $table->boolean('closed')->default(false);
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('forum__poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('forum__polls')->cascadeOnDelete();
            $table->string('label', 160);
            $table->unsignedTinyInteger('position');
        });
        Schema::create('forum__poll_ballots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('forum__polls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['poll_id', 'user_id']);
        });
        Schema::create('forum__poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ballot_id')->constrained('forum__poll_ballots')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('forum__poll_options')->cascadeOnDelete();
            $table->unique(['ballot_id', 'option_id']);
        });
    }

    private function createRelatedTables(): void
    {
        Schema::create('forum__category_roles', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('forum__categories')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('ability', 8);
            $table->primary(['category_id', 'role_id', 'ability']);
        });
        Schema::create('forum__tag_roles', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained('forum__tags')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['tag_id', 'role_id']);
        });
        Schema::create('forum__category_tags', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('forum__categories')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('forum__tags')->cascadeOnDelete();
            $table->primary(['category_id', 'tag_id']);
        });
        Schema::create('forum__profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('signature')->nullable();
            $table->string('bio', 1000)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('discord', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('forum__subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum__topics')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_post_id')->default(0);
            $table->unsignedBigInteger('last_notified_post_id')->default(0);
            $table->unsignedBigInteger('pending_post_id')->nullable();
            $table->timestamps();
            $table->unique(['topic_id', 'user_id']);
            $table->index(['pending_post_id', 'id']);
        });
        Schema::create('forum__mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum__posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('notice_id')->nullable()->constrained('community_notices')->nullOnDelete();
            $table->string('state', 16)->default('pending');
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
            $table->index(['state', 'id']);
        });
        Schema::create('forum__attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained('forum__posts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('forum__categories')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 240)->unique();
            $table->string('name', 180);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('created_at');
            $table->index(['post_id', 'user_id']);
        });
        Schema::create('forum__poll_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('forum__polls')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['poll_id', 'created_at', 'id'], 'forum_poll_revisions_retention_index');
        });
        Schema::create('forum__drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('context', 80);
            $table->longText('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'context']);
        });
        Schema::create('forum__announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('post_id')->unique()->constrained('forum__posts')->cascadeOnDelete();
            $table->uuid('configuration_id');
            $table->string('message_id', 30)->nullable();
            $table->string('event', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }
};
