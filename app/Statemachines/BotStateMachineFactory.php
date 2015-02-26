<?php

namespace Swapbot\Statemachines;

use Exception;
use Illuminate\Support\Facades\Log;
use MetaborStd\Event\EventInterface;
use Metabor\Statemachine\Process;
use Metabor\Statemachine\Statemachine;
use Metabor\Statemachine\Transition;
use Swapbot\Models\Bot;
use Swapbot\Models\Data\BotState;
use Swapbot\Models\Data\BotStateEvent;
use Swapbot\Repositories\BotRepository;
use Swapbot\Statemachines\BotCommand\CreationFeePaid;
use Swapbot\Statemachines\BotCommand\Fueled;

/*
* BotStateMachineFactory
*/
class BotStateMachineFactory {

    public function __construct(BotRepository $bot_repository) {
        $this->bot_repository = $bot_repository;
    }

    public function buildStateMachineFromBot(Bot $bot) {
        // build a statemachine
        $state_machine = new Statemachine($bot, $this->buildStateMachineProcess($bot['state']));

        return $state_machine;

    }

    public function buildStateMachineProcess($initial_state_name, $process_name='Swapbot State Machine') {
        // build the statues
        $states = $this->buildStates();

        // build transitions
        $this->addTransitionsToStates($states);

        // get the initial state
        if (!isset($states[$initial_state_name])) { throw new Exception("No such state: $initial_state_name", 1); }

        // build a process that handles transitions
        $process = new Process($process_name, $states[$initial_state_name]);

        return $process;
    }

    public function buildStates() {
        // build states
        return [
            BotState::BRAND_NEW => new BotState(BotState::BRAND_NEW),
            BotState::LOW_FUEL  => new BotState(BotState::LOW_FUEL),
            BotState::ACTIVE    => new BotState(BotState::ACTIVE),
            BotState::INACTIVE  => new BotState(BotState::INACTIVE),
        ];

    }

    // add transitions
    public function addTransitionsToStates($states) {
        // BotState::BRAND_NEW => BotState::LOW_FUEL
        $states[BotState::BRAND_NEW]->addTransition(new Transition($states[BotState::LOW_FUEL], BotStateEvent::CREATION_FEE_PAID));
        $states[BotState::BRAND_NEW]->getEvent(BotStateEvent::CREATION_FEE_PAID)->attach(new CreationFeePaid());

        // BotState::LOW_FUEL => BotState::LOW_FUEL
        $states[BotState::LOW_FUEL]->addTransition(new Transition($states[BotState::LOW_FUEL], BotStateEvent::CREATION_FEE_PAID));

        // BotState::LOW_FUEL => BotState::ACTIVE
        $states[BotState::LOW_FUEL]->addTransition(new Transition($states[BotState::ACTIVE], BotStateEvent::FUELED));
        $states[BotState::LOW_FUEL]->getEvent(BotStateEvent::FUELED)->attach(new Fueled());

        return $states;
    }


}