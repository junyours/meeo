
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughterhouse_collection_items', function (Blueprint $table) {

            // Connect collection item to Animal model
            $table->unsignedBigInteger('animal_id')
                ->nullable()
                ->after('slaughterhouse_collection_id');

            $table->foreign('animal_id', 'slh_item_animal_fk')
                ->references('id')
                ->on('animals')
                ->nullOnDelete();
 $table->json('animal_weights')
                ->nullable()
                ->after('total_kilos');
        });
    }

    public function down(): void
    {
        Schema::table('slaughterhouse_collection_items', function (Blueprint $table) {

            $table->dropForeign('slh_item_animal_fk');
            $table->dropColumn('animal_id');

        });
    }
};
