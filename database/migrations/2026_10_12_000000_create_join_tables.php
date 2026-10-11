<?php

namespace AfazTech\ReactorJoin\Migrations;

use Reactor\Database\Migrations\Migration;
use Reactor\Contracts\DatabaseManagerInterface;

class CreateJoinTables extends Migration
{
    public function __construct(DatabaseManagerInterface $db)
    {
        parent::__construct($db);
    }

    public function up(): void
    {
        if (!$this->db->schema()->hasTable('required_channels')) {
            $this->db->schema()->create('required_channels', function ($table) {
                $table->id();
                $table->bigInteger('chat_id')->unique();
                $table->string('title', 255);
                $table->string('link', 512);
                $table->boolean('is_active')->default(true);
                // 0 = no limit. Auto-deactivate when the channel reaches this member count.
                $table->unsignedInteger('target_members')->default(0);
                // 0 = feature disabled. Auto-deactivate after this many joined users.
                $table->unsignedInteger('max_adds')->default(0);
                // Running counter of users who joined through the bot.
                $table->unsignedInteger('current_adds')->default(0);
                $table->timestamps();
                $table->index('is_active');
            });
        }

        if (!$this->db->schema()->hasTable('channel_members')) {
            $this->db->schema()->create('channel_members', function ($table) {
                $table->id();
                $table->bigInteger('user_id');
                $table->unsignedBigInteger('channel_id');
                $table->boolean('is_joined')->default(false);
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'channel_id']);
                $table->index('user_id');
                $table->index(['channel_id', 'is_joined']);
            });
        }
    }

    public function down(): void
    {
        $this->db->schema()->dropIfExists('channel_members');
        $this->db->schema()->dropIfExists('required_channels');
    }
}
