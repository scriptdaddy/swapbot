<?php

namespace Swapbot\Console\Commands\Swap;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Swapbot\Models\Data\SwapStateEvent;
use Swapbot\Repositories\SwapRepository;
use Swapbot\Swap\Logger\BotEventLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Illuminate\Console\ConfirmableTrait;

class ErrorSwapCommand extends Command {

    use DispatchesCommands;
    use ConfirmableTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'swapbot:error-swap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Puts a Swap to a permanenterror state.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['swap-id', InputArgument::REQUIRED, 'A swap ID to error.'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production.'],
        ];
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        try {
            // get a single swap and process it
            $swap_id = $this->input->getArgument('swap-id');
            $swap_repository = app('Swapbot\Repositories\SwapRepository');
            $swap = $swap_repository->findByUuid($swap_id);
            if (!$swap) { $swap = $swap_repository->findByID($swap_id); }
            if (!$swap) { throw new Exception("Unable to find swap", 1); }

            $this->comment('Found swap '.$swap['uuid'].' for bot '.$swap->bot['name']);

            // require confirmation
            if (!$this->confirmToProceed()) { return; }

            $swap_repository->executeWithLockedSwap($swap, function($locked_swap) {
                $this->comment('Moving from state '.$locked_swap['state']);
                $locked_swap->stateMachine()->triggerEvent(SwapStateEvent::SWAP_PERMANENTLY_ERRORED);
            });

            $this->info('done');


        } catch (Exception $e) {
            $this->error('Error: '.$e->getMessage());
            throw $e;
        }
    }

}
