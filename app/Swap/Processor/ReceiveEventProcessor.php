<?php

namespace Swapbot\Swap\Processor;

use ArrayObject;
use Exception;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Swapbot\Commands\ProcessPendingSwap;
use Swapbot\Commands\ProcessPendingSwapsForBot;
use Swapbot\Commands\ReconcileBotPaymentState;
use Swapbot\Commands\ReconcileBotState;
use Swapbot\Commands\ReconcileBotSwapStates;
use Swapbot\Commands\UpdateBotBalances;
use Swapbot\Commands\UpdateBotPaymentAccount;
use Swapbot\Models\Bot;
use Swapbot\Models\BotEvent;
use Swapbot\Models\Data\BotState;
use Swapbot\Repositories\BlockRepository;
use Swapbot\Repositories\BotRepository;
use Swapbot\Repositories\SwapRepository;
use Swapbot\Repositories\TransactionRepository;
use Swapbot\Statemachines\BotStateMachineFactory;
use Swapbot\Swap\Logger\BotEventLogger;
use Swapbot\Swap\Processor\ReceivePaymentProcessor;
use Swapbot\Swap\Processor\SwapProcessor;
use Swapbot\Swap\Processor\Util\BalanceUpdater;
use Tokenly\LaravelEventLog\Facade\EventLog;

class ReceiveEventProcessor {

    use DispatchesCommands;

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct(BotRepository $bot_repository, SwapRepository $swap_repository, BlockRepository $block_repository, TransactionRepository $transaction_repository, SwapProcessor $swap_processor, ReceivePaymentProcessor $receive_payment_processor, BotEventLogger $bot_event_logger, BalanceUpdater $balance_updater)
    {
        $this->bot_repository            = $bot_repository;
        $this->block_repository          = $block_repository;
        $this->swap_repository           = $swap_repository;
        $this->transaction_repository    = $transaction_repository;
        $this->swap_processor            = $swap_processor;
        $this->bot_event_logger          = $bot_event_logger;
        $this->receive_payment_processor = $receive_payment_processor;
        $this->balance_updater           = $balance_updater;
    }


    public function handleReceive($xchain_notification) {
        $found = false;

        // find a bot for this notification if it is received on a public address
        $bot = $this->bot_repository->findByPublicMonitorID($xchain_notification['notifiedAddressId']);
        if ($bot) { 
            $block_height = $this->getCurrentBlockHeight($xchain_notification);
            $public_tx_process = $this->handlePublicAddressReceive($xchain_notification, $bot, $block_height);
            $found = true;


            // process any swaps that were created or affected
            $this->processAnySwapsAffected($bot, $public_tx_process['transaction']['id'], $block_height);
        }

        // find a bot for this notification if it is received on the payment address
        if (!$found) {
            $bot = $this->bot_repository->findByPaymentMonitorID($xchain_notification['notifiedAddressId']);
            if ($bot) { 
                $this->receive_payment_processor->handlePaymentAddressReceive($xchain_notification, $bot);
                $found = true;

                // process any swaps that might have been waiting for payment
                $block_height = $this->getCurrentBlockHeight($xchain_notification);
                $this->processAnySwapsAffected($bot, null, $block_height);
            }
        }

        // this was for a bot that doesn't exist
        if (!$found) {
            EventLog::logError('receive.error', ['reason' => 'no bot found', 'notificationId' => $xchain_notification['notificationId']]);
            throw new Exception("Unable to find bot for monitor {$xchain_notification['notifiedAddressId']}.  notificationId was {$xchain_notification['notificationId']}", 1);
        }

    }

    // returns an array of data with
    //   new_swaps_created
    public function handlePublicAddressReceive($xchain_notification, $bot, $block_height) {
        $tx_process = $this->bot_repository->executeWithLockedBot($bot, function($bot) use ($xchain_notification, $block_height) {

            // load or create a new transaction from the database
            $transaction_model = $this->findOrCreateTransaction($xchain_notification, $bot['id'], 'receive');
            if (!$transaction_model) { throw new Exception("Unable to access database", 1); }
            // Log::debug("xchain notification received: {$xchain_notification['confirmations']}");

            // initialize a DTO (data transfer object) to hold all the variables
            $tx_process = new ArrayObject([
                'transaction'                      => $transaction_model,
                'xchain_notification'              => $xchain_notification,
                'bot'                              => $bot,
                'block_height'                     => $block_height,

                'confirmations'                    => $xchain_notification['confirmations'],
                'is_confirmed'                     => $xchain_notification['confirmed'],
                'destination'                      => $xchain_notification['sources'][0],

                'tx_is_handled'                    => false,
                'transaction_update_vars'          => [],

                'any_processing_errors'            => false,
                'should_reconcile_bot_state'       => true,
                'should_reconcile_swap_states'     => true,

                'new_swaps_created'                => [],
            ]);

            // previously processed transactions
            $this->checkForPreviouslyProcessedTransaction($tx_process);

            // check for incoming fuel transaction
            $this->checkForIncomingFuelTransaction($tx_process);

            // check for blacklisted sources
            $this->checkForBlacklistedAddresses($tx_process);

            // reconcile the bot state before processing swaps
            $this->handleReconcileBotState($tx_process);
            
            // check bot state
            $this->checkBotState($tx_process);

            // process all newly created swaps
            $this->createNewSwaps($tx_process);

            // update bot balances
            $this->updateBotBalances($tx_process);

            // done going through swaps - update the transaction
            $this->updateTransaction($tx_process);

            // when done, reconcile the bot state if needed
            $this->handleReconcileBotState($tx_process);
            
            // when done, reconcile the bot's swap states if needed
            $this->handleReconcileSwapStates($tx_process);

            return $tx_process;
        });

        return [
            'transaction' => $tx_process['transaction'],
        ];
    }

    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Transaction
    
    protected function findOrCreateTransaction($xchain_notification, $bot_id, $type) {
        return $this->transaction_repository->findOrCreateTransaction($xchain_notification['txid'], $bot_id, $type, ['xchain_notification' => $xchain_notification]);
    }




    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // checks

    protected function checkForPreviouslyProcessedTransaction($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }

        if ($tx_process['transaction']['processed']) {
            $this->bot_event_logger->logPreviousTransaction($tx_process['bot'], $tx_process['xchain_notification']);

            // update the notification and the confirmations
            $tx_process['transaction_update_vars']['xchain_notification'] = $tx_process['xchain_notification'];
            $tx_process['transaction_update_vars']['confirmations']       = $tx_process['confirmations'];

            // the bot state may have changed
            $tx_process['should_reconcile_bot_state']   = false;

            // and we do need to reconcile swap states
            $tx_process['should_reconcile_swap_states'] = false;

            // mark the transaction as handled
            $tx_process['tx_is_handled'] = true;
        }
    }    

    protected function updateBotBalances($tx_process) {
        if ($tx_process['transaction']['balances_applied']) { return; }

        if ($tx_process['is_confirmed'] AND $tx_process['confirmations'] >= BalanceUpdater::XCHAIN_INCOMING_CONFIRMATIONS_REQUIRED) {
            // update the bot's balance
            $this->balance_updater->syncBalances($tx_process['bot']);
            $tx_process['transaction_update_vars']['balances_applied'] = true;
        }
    }

    // is this a fuel top-up?
    protected function checkForIncomingFuelTransaction($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }
        if ($tx_process['transaction']['processed']) { return; }

        if ($tx_process['xchain_notification']['asset'] == 'BTC' AND in_array(Config::get('swapbot.xchain_fuel_pool_address'), $tx_process['xchain_notification']['sources'])) {
            $confirmed = $tx_process['is_confirmed'];

            // this is a fuel transaction
            if ($confirmed) {
                $this->bot_event_logger->logFuelTXReceived($tx_process['bot'], $tx_process['xchain_notification']);

                // fuel is not completely processed until 2 transactions are received
                if ($tx_process['confirmations'] >= BalanceUpdater::XCHAIN_INCOMING_CONFIRMATIONS_REQUIRED) {
                    $tx_process['transaction_update_vars']['processed'] = true;
                }
            } else {
                $this->bot_event_logger->logUnconfirmedFuelTXReceived($tx_process['bot'], $tx_process['xchain_notification']);
            }

            $tx_process['tx_is_handled']                            = true;
            $tx_process['transaction_update_vars']['confirmations'] = $tx_process['confirmations'];
        }

    }

    // check for blacklisted sources
    protected function checkForBlacklistedAddresses($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }
        if ($tx_process['transaction']['processed']) { return; }

        $blacklist_addresses = $tx_process['bot']['blacklist_addresses'];

        // never process a transaction coming from the same address that is receiving it
        $blacklist_addresses[] = $tx_process['xchain_notification']['notifiedAddress'];

        // never process a transaction coming from the payment address
        $blacklist_addresses[] = $tx_process['bot']['payment_address'];

        // never process a transaction coming from any income forwarding addresses
        foreach ($tx_process['bot']['income_rules'] as $income_rule_config) {
            $blacklist_addresses[] = $income_rule_config['address'];
        }

        if (in_array($tx_process['xchain_notification']['sources'][0], $blacklist_addresses)) {
            // blacklisted
            $this->bot_event_logger->logSendFromBlacklistedAddress($tx_process['bot'], $tx_process['xchain_notification'], $tx_process['is_confirmed']);

            $tx_process['tx_is_handled']                            = true;
            $tx_process['transaction_update_vars']['confirmations'] = $tx_process['confirmations'];

            if ($tx_process['is_confirmed']) {
                // only mark as processed when confirmed
                $tx_process['transaction_update_vars']['processed']     = true;
            }
        }

    }


    protected function checkBotState($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }

        $bot_state = $tx_process['bot']->stateMachine()->getCurrentState();
        // Log::debug('checkBotState bot_state: '.$bot_state->getName());

        // if the bot is not active, then mark it as handled
        if (!$bot_state->isActive()) {
            switch ($bot_state->getName()) {
                case BotState::INACTIVE:
                    // this bot is manually set to inactive
                    $this->bot_event_logger->logInactiveBotState($tx_process['bot'], $tx_process['xchain_notification'], $bot_state);

                    // since the bot was manually set to inactive, no swaps are created
                    $tx_process['tx_is_handled'] = true;

                    break;
                
                default:
                    // this bot is inactive due to another inactive state such as low fuel
                    $this->bot_event_logger->logInactiveBotState($tx_process['bot'], $tx_process['xchain_notification'], $bot_state);

                    break;
            }


            // a manually inactive bot still marks the transaction as processed
            if ($bot_state->getName() == BotState::INACTIVE) {
                $tx_process['transaction_update_vars']['processed']     = true;
                $tx_process['transaction_update_vars']['confirmations'] = $tx_process['confirmations'];
            }
        }

        // special case for shutting down
        if ($bot_state->getName() == BotState::SHUTTING_DOWN) {
            $this->bot_event_logger->logShuttingDownTransactionReceived($tx_process['bot'], $tx_process['xchain_notification']);
        }
    }

    protected function createNewSwaps($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }

        $bot = $tx_process['bot'];

        $any_swap_processed     = false;
        $all_matched_swaps_sent = true;
        foreach ($bot['swaps'] as $swap_config) {
            $was_processed = false;

            // only process if the incoming asset matches the swap config
            $should_process = ($tx_process['xchain_notification']['asset'] == $swap_config['in']);
            if ($should_process) {
                // build a swap from this swap config
                $swap = $this->swap_processor->findSwapFromSwapConfig($swap_config, $tx_process['bot']['id'], $tx_process['transaction']['id']);

                // if there wasn't a swap yet, create one
                if (!$swap) {
                    $swap = $this->swap_processor->createNewSwap($swap_config, $tx_process['bot'], $tx_process['transaction']);

                    $tx_process['new_swaps_created'][] = $swap;
                }

                $any_swap_processed = true;
            }
        }

        if (!$any_swap_processed) {
            // we received an asset, but no swap was processed
            //   this was probably a transaction to fill up the bot
            $this->bot_event_logger->logUnknownReceiveTransaction($bot, $tx_process['xchain_notification']);
        }

        // mark this transaction as processed (completed)
        $tx_process['transaction_update_vars']['processed']     = true;

        // update the confirmations
        $tx_process['transaction_update_vars']['confirmations'] = $tx_process['confirmations'];
    }

    protected function updateTransaction($tx_process) {
        if ($tx_process['transaction_update_vars']) {

            $update_vars = $tx_process['transaction_update_vars'];

            $this->transaction_repository->update($tx_process['transaction'], $update_vars);
        }
    }





    protected function handleReconcileBotState($tx_process) {
        if ($tx_process['should_reconcile_bot_state']) {
            // the bot state might have changed, so check it now
            $this->dispatch(new ReconcileBotState($tx_process['bot']));
            $this->dispatch(new ReconcileBotPaymentState($tx_process['bot']));

            // reload the bot
            $this->reloadBot($tx_process);
        }
    }
    protected function handleReconcileSwapStates($tx_process) {
        if ($tx_process['should_reconcile_swap_states']) {
            // some swap states might have changed, so check those
            $this->dispatch(new ReconcileBotSwapStates($tx_process['bot'], $tx_process['block_height']));
        }
    }

    protected function reloadBot($tx_process) {
        $tx_process['bot'] = $this->bot_repository->findByID($tx_process['bot']['id']);
    }

    protected function processAnySwapsAffected(Bot $bot, $transaction_id, $block_height) {
        $any_swap_processed = false;

        if ($transaction_id) {
            $swaps_updated = $this->swap_repository->findByTransactionID($transaction_id);
            foreach ($swaps_updated as $swap) {
                $this->dispatch(new ProcessPendingSwap($swap, $block_height));
                $any_swap_processed = true;
            }
        }

        if (!$any_swap_processed) {
            // maybe this was a refueling transaction
            $this->dispatch(new ProcessPendingSwapsForBot($bot, $block_height));
        }

    }


    protected function getCurrentBlockHeight($xchain_notification) {
        $block_height = (isset($xchain_notification['bitcoinTx']) AND isset($xchain_notification['bitcoinTx']['blockheight'])) ? $xchain_notification['bitcoinTx']['blockheight'] : null;
        if (!$block_height) { $block_height = $this->block_repository->findBestBlockHeight(); }
        return $block_height;
    }

}
