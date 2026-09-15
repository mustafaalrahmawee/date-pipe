<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('import_id')->constrained()->cascadeOnDelete();
            // CSV row number as shown in an editor: header row is 1,
            // the first data row is 2. Error reports reuse this number.
            $table->unsignedInteger('row_index');
            $table->string('name');
            $table->string('email');
            $table->decimal('amount', 12, 6);

            $table->unique(['import_id', 'row_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
