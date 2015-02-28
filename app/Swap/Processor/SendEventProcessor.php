<?php

namespace Swapbot\Swap\Processor;

use ArrayObject;
use Exception;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Swapbot\Commands\UpdateBotBalances;
use Swapbot\Models\BotEvent;
use Swapbot\Models\Data\SwapState;
use Swapbot\Models\Data\SwapStateEvent;
use Swapbot\Repositories\BotRepository;
use Swapbot\Repositories\SwapRepository;
use Swapbot\Repositories\TransactionRepository;
use Swapbot\Swap\Logger\BotEventLogger;
use Tokenly\LaravelEventLog\Facade\EventLog;

class SendEventProcessor {

    use DispatchesCommands;

    /**
     * Create the command handler.
     *
     * @return void
     */
    public function __construct(BotRepository $bot_repository, SwapRepository $swap_repository, TransactionRepository $transaction_repository, BotEventLogger $bot_event_logger)
    {
        $this->bot_repository         = $bot_repository;
        $this->swap_repository        = $swap_repository;
        $this->transaction_repository = $transaction_repository;
        $this->bot_event_logger       = $bot_event_logger;
    }


    public function handleSend($xchain_notification) {
        // find the bot related to this notification
        $bot = $this->bot_repository->findBySendMonitorID($xchain_notification['notifiedAddressId']);
        if (!$bot) { throw new Exception("Unable to find bot for monitor {$xchain_notification['notifiedAddressId']}", 1); }

        // lock the transaction
        DB::transaction(function() use ($xchain_notification, $bot) {

            // load or create a new transaction from the database
            $transaction_model = $this->findOrCreateTransaction($xchain_notification, $bot['id']);
            if (!$transaction_model) { throw new Exception("Unable to access database", 1); }

            // initialize a DTO (data transfer object) to hold all the variables
            $tx_process = new ArrayObject([
                'transaction'                  => $transaction_model,
                'xchain_notification'          => $xchain_notification,
                'bot'                          => $bot,

                'confirmations'                => $xchain_notification['confirmations'],
                'is_confirmed'                 => $xchain_notification['confirmed'],
                'destination'                  => $xchain_notification['sources'][0],

                'tx_is_handled'                => false,
                'transaction_update_vars'      => [],
                'should_update_bot_balance'    => ($xchain_notification['confirmed'] ? true : false),
                'bot_balance_deltas'           => [],
            ]);



            // previously processed transaction
            $this->handlePreviouslyProcessedTransaction($tx_process);

            // process all swaps
            $this->processSwaps($tx_process);

            // done going through swaps - update the transaction
            $this->updateTransaction($tx_process);
        });


        // bot balance update must be done outside of the transaction
        // if ($should_update_bot_balance) {
        //     $this->updateBotBalance($bot);
        // }

        return $bot;
    }

    ////////////////////////////////////////////////////////////////////////

    protected function handlePreviouslyProcessedTransaction($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }

        $transaction_model = $tx_process['transaction'];
        if ($transaction_model['processed']) {
            $xchain_notification = $tx_process['xchain_notification'];
            $bot = $tx_process['bot'];
            $this->bot_event_logger->logToBotEvents($bot, 'send.previous', BotEvent::LEVEL_DEBUG, [
                'msg'  => "Send transaction {$xchain_notification['txid']} has already been processed.  Ignoring it.",
                'txid' => $xchain_notification['txid']
            ]);
            $tx_process['tx_is_handled'] = true;
        }
    }


    protected function processSwaps($tx_process) {
        if ($tx_process['tx_is_handled']) { return; }

        $bot                 = $tx_process['bot'];
        $xchain_notification = $tx_process['xchain_notification'];
        $is_confirmed        = $tx_process['is_confirmed'];
        $destination         = $xchain_notification['destinations'][0];
        $quantity            = $xchain_notification['quantity'];
        $asset               = $xchain_notification['asset'];
        $confirmations       = $xchain_notification['confirmations'];


        // get all swaps that are in state sent
        $txid = $tx_process['xchain_notification']['txid'];
        $states = [SwapState::SENT];
        $swaps = $this->swap_repository->findByBotIDWithStates($bot['id'], $states);

        $any_swap_matched = false;
        foreach($swaps as $swap) {
            Log::debug("txid=$txid receipt={$swap['receipt']['txid']}");
            if ($swap['receipt']['txid'] == $txid) {
                $any_swap_matched = true;

                if ($is_confirmed) {
                    // confirmed transaction
                    $this->bot_event_logger->logConfirmedSendTx($bot, $xchain_notification, $destination, $quantity, $asset, $confirmations);

                    //   move the swap into state completed
                    $swap->stateMachine()->triggerEvent(SwapStateEvent::SWAP_COMPLETED);
                    $tx_process['tx_is_handled'] = true;

                    // this transaction was processed
                    $tx_process['transaction_update_vars']['processed']     = true;
                    $tx_process['transaction_update_vars']['confirmations'] = $tx_process['xchain_notification']['confirmations'];
                } else {
                    // just an unconfirmed transaction
                    $this->bot_event_logger->logUnconfirmedSendTx($bot, $xchain_notification, $destination, $quantity, $asset);
                }
            }
        }

        if (!$any_swap_matched) {
            $this->bot_event_logger->logUnknownSendTransaction($bot, $xchain_notification);
        }
    }


    protected function updateTransaction($tx_process) {
        if ($tx_process['transaction_update_vars']) {

            $update_vars = $tx_process['transaction_update_vars'];
            $this->transaction_repository->update($tx_process['transaction'], $update_vars);
        }
    }



    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Balance
    
    // protected function updateBotBalance($bot) {
    //     try {
    //         $this->dispatch(new UpdateBotBalances($bot));
    //     } catch (Exception $e) {
    //         // log any failure
    //         EventLog::logError('balanceupdate.failed', $e);
    //         $this->bot_event_logger->logBalanceUpdateFailed($bot, $e);
    //     }
    // }


    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Transaction
    
    protected function findOrCreateTransaction($xchain_notification, $bot_id) {
        $transaction_model = $this->transaction_repository->findByTransactionIDAndBotIDWithLock($xchain_notification['txid'], $bot_id);
        if ($transaction_model) { return $transaction_model; }

        // create a new transaction
        return $this->transaction_repository->create([
            'txid'                => $xchain_notification['txid'],
            'bot_id'              => $bot_id,
            'xchain_notification' => $xchain_notification,
        ]);
    }





    ////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////
    // Desc
    


}
