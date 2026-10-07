
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('slaughterhouse_collection_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('slaughterhouse_collection_id');

            $table->integer('quantity');
            $table->string('animal_type');
            $table->decimal('total_kilos', 12, 3)->default(0);
            $table->decimal('price_kilos', 12, 2)->default(0);
            $table->decimal('ante_mortem', 12, 2)->default(0);
            $table->decimal('post_mortem', 12, 2)->default(0);
            $table->decimal('hides', 12, 2)->default(0);
            $table->decimal('slaughter_fee', 12, 2)->default(0);
            $table->decimal('coral_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->softDeletes();
            $table->timestamps();

            // Foreign key
            $table->foreign(
                'slaughterhouse_collection_id',
                'slh_collection_item_fk'
            )
                ->references('id')
                ->on('slaughterhouse_collections')
                ->cascadeOnDelete();

            // Indexes with short names
            $table->index('animal_type', 'slh_item_animal_type_idx');
            $table->index(
                'slaughterhouse_collection_id',
                'slh_collection_id_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slaughterhouse_collection_items');
    }
};

