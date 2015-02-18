<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBotsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('bots', function(Blueprint $table)
		{
			$table->increments('id');
            $table->char('uuid', 36)->unique();
			$table->string('name');
			$table->text('description');
			$table->text('swaps');
            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');

            $table->string('address', 35)->unique()->nullable();
            $table->string('payment_address_id', 36)->unique()->nullable();
            $table->string('receive_monitor_id', 36)->unique()->nullable();
            $table->string('send_monitor_id', 36)->unique()->nullable();

            $table->text('balances')->nullable();
            $table->timestamp('balances_updated_at')->nullable();

            $table->text('blacklist_addresses')->nullable();

            $table->bigInteger('return_fee')->unsigned()->default(10000);  // 0.0001

            $table->boolean('active')->default(0);
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
		Schema::drop('bots');
	}

}
