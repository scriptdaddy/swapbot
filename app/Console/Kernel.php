<?php namespace Swapbot\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel {

	/**
	 * The Artisan commands provided by your application.
	 *
	 * @var array
	 */
	protected $commands = [
		'Swapbot\Console\Commands\Bot\ActivateBotCommand',
		'Swapbot\Console\Commands\Bot\ListAllBotsCommand',
		'Swapbot\Console\Commands\Bot\ReconcileBotStateCommand',
		'Swapbot\Console\Commands\Bot\UpdateBotBalancesCommand',
		'Swapbot\Console\Commands\Bot\ChangeBotStateCommand',

		'Swapbot\Console\Commands\Development\ExportBotStateGraph',
		'Swapbot\Console\Commands\Development\ExportSwapStateGraph',
		'Swapbot\Console\Commands\Development\UpdateBotPaymentAccount',

		'Swapbot\Console\Commands\Development\CreateTestSwapCommand',
		'Swapbot\Console\Commands\Development\TestCreateBotBalancesUpdateCommand',
		'Swapbot\Console\Commands\Development\TestCreateBotEventCommand',
		'Swapbot\Console\Commands\Development\TestReceiveXChainNotificationCommand',
		'Swapbot\Console\Commands\Development\PopulateMissingSwapReceiptsCommand',
	];

	/**
	 * Define the application's command schedule.
	 *
	 * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
	 * @return void
	 */
	protected function schedule(Schedule $schedule)
	{
		$schedule->command('inspire')
				 ->hourly();
	}

}
