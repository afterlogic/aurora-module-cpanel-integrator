<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class CreateAliasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Capsule::schema()->create('cpanel_aliases', function (Blueprint $table) {
            $table->id('Id');
            $table->integer('IdUser')->default(0);
            $table->integer('IdAccount')->default(0);
            $table->string('Email')->default('');
            $table->string('ForwardTo')->default('');
            $table->string('FriendlyName')->default('');
            $table->boolean('UseSignature')->default(false);
            $table->text('Signature');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Capsule::schema()->dropIfExists('cpanel_aliases');
    }
}
