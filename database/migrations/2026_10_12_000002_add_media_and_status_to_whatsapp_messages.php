<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_messages')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('whatsapp_messages', 'status')) {
                    $table->string('status', 20)->nullable()->index()
                        ->comment('Delivery receipt for outbound: sent/delivered/read');
                }
                if (! Schema::hasColumn('whatsapp_messages', 'media_type')) {
                    $table->string('media_type', 20)->nullable()
                        ->comment('image, video, audio, document, sticker, location, contact');
                }
                if (! Schema::hasColumn('whatsapp_messages', 'media_path')) {
                    $table->string('media_path')->nullable()
                        ->comment('Relative path on the private local disk');
                }
                if (! Schema::hasColumn('whatsapp_messages', 'media_name')) {
                    $table->string('media_name')->nullable()
                        ->comment('Original file name (documents)');
                }
                if (! Schema::hasColumn('whatsapp_messages', 'media_mime')) {
                    $table->string('media_mime')->nullable();
                }
                if (! Schema::hasColumn('whatsapp_messages', 'media_size')) {
                    $table->unsignedBigInteger('media_size')->nullable();
                }
            });
        }

        if (! Schema::hasTable('whatsapp_conversation_seens')) {
            Schema::create('whatsapp_conversation_seens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('last_seen_message_id')->nullable();
                $table->timestamps();

                $table->unique(['whatsapp_conversation_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_seens');

        if (Schema::hasTable('whatsapp_messages')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                foreach (['status', 'media_type', 'media_path', 'media_name', 'media_mime', 'media_size'] as $column) {
                    if (Schema::hasColumn('whatsapp_messages', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
