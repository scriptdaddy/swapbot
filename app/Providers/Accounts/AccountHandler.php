<?php

namespace Swapbot\Providers\Accounts;

use Exception;
use Illuminate\Support\Facades\Log;
use Swapbot\Models\Swap;
use Swapbot\Models\Transaction;
use Swapbot\Swap\Logger\Facade\BotEventLogger;
use Swapbot\Swap\Processor\SwapProcessor;
use Swapbot\Swap\Processor\Util\BalanceUpdater;
use Tokenly\LaravelEventLog\Facade\EventLog;
use Tokenly\XChainClient\Client;
use Tokenly\XChainClient\Exception\XChainException;

class AccountHandler {

    function __construct(Client $xchain_client, BalanceUpdater $balance_updater) {
        $this->xchain_client   = $xchain_client;
        $this->balance_updater = $balance_updater;
    }

    public function swapAccountName(Swap $swap) {
        return 'swap-'.$swap['uuid'];
    }

    public function moveIncomingReceivedFunds(Swap $swap) {
        $this->xchainClientCall(function($xchain) use ($swap) {
            $transaction = $swap->transaction;
            $bot         = $swap->bot;
            $txid        = $transaction['txid'];
            // Log::debug("allocateNewSwapAccount calling transferAllByTransactionID for {$bot['public_address_id']} on swap {$swap['uuid']}");

            // allow failure
            try {
                $success = $xchain->transferAllByTransactionID($bot['public_address_id'], 'default', $this->swapAccountName($swap), $txid);
                BotEventLogger::logTransferIncome($bot, $swap, $txid, 'default', $this->swapAccountName($swap));
            } catch (XChainException $e) {
                BotEventLogger::logTransferIncomeFailed($bot, $swap, $e, $txid, 'default', $this->swapAccountName($swap));
                $success = false;
            }
            return $success;
        });
    }

    // returns false if the stock was not allocated
    //   transfers all or nothing for what the swap wants
    public function allocateStock(Swap $swap) {
        $transfer_results = [
            'all_succeeded'     => true,
            'stock_transferred' => null,
            'btc_transferred'   => null,
        ];

        $account_name = $this->swapAccountName($swap);
        $bot = $swap->bot;

        $actual_balances = $this->xchainClientCall(function($xchain) use ($swap, $bot, $account_name) {
            return $xchain->getAccountBalances($bot['public_address_id'], $account_name, 'confirmed');
        });

        $default_balances = $this->xchainClientCall(function($xchain) use ($swap, $bot, $account_name) {
            return $xchain->getAccountBalances($bot['public_address_id'], 'default', 'confirmed');
        });

        $all_succeeded = true;
        $balances_desired = $this->buildOutputBalancesNeeded($swap);
        Log::debug("\$balances_desired:".json_encode($balances_desired, 192));
        foreach($balances_desired as $asset => $desired_quantity) {
            $actual_quantity = isset($actual_balances[$asset]) ? $actual_balances[$asset] : 0;
            $needed_quantity = $desired_quantity - $actual_quantity;
            if ($needed_quantity > 0) {
                $default_quantity = isset($default_balances[$asset]) ? $default_balances[$asset] : 0;
                if ($default_quantity < $needed_quantity) {
                    Log::debug("$default_quantity (default) < $needed_quantity (needed) for $asset");
                    // swapbot is reporting that it does not have enough stock
                    $transferred_successfully = false;
                } else {
                    // stock looks good
                    // transfer from the default to the swap account
                    $transferred_successfully = $this->xchainClientCall(function($xchain) use ($swap, $bot, $account_name, $needed_quantity, $asset) {
                        return $xchain->transfer($bot['public_address_id'], 'default', $account_name, $needed_quantity, $asset);
                    });

                    if ($transferred_successfully !== false) {
                        BotEventLogger::logTransferInventory($bot, $swap, $needed_quantity, $asset, 'default', $account_name);

                        // update the bot with the new balances
                        $this->balance_updater->syncBalances($bot);
                    } else {
                        BotEventLogger::logTransferInventoryFailed($bot, $swap, $needed_quantity, $asset, 'default', $account_name);
                    }
                }


                Log::debug("\$transferred_successfully=".json_encode($transferred_successfully, 192));
                if ($transferred_successfully == true) {
                    if ($asset == 'BTC') {
                        if ($transfer_results['btc_transferred'] === null) { $transfer_results['btc_transferred'] = true; }
                    } else {
                        $transfer_results['stock_transferred'] = true;
                    }
                } else {
                    $transfer_results['all_succeeded'] = false;
                    if ($asset == 'BTC') {
                        $transfer_results['btc_transferred'] = false;
                    } else {
                        $transfer_results['stock_transferred'] = false;
                    }
                }
            }
        }

        if ($transfer_results['stock_transferred'] === null) {
            $transfer_results['stock_transferred'] = $transfer_results['btc_transferred'];
        }


        return $transfer_results;
    }


    // closes the swap account and transfers all funds back to the default account
    public function closeSwapAccount(Swap $swap) {
        $account_name = $this->swapAccountName($swap);
        $bot = $swap->bot;

        $closed_actual_balances = $this->xchainClientCall(function($xchain) use ($swap, $bot, $account_name) {
            return $xchain->getAccountBalances($bot['public_address_id'], $account_name);
        });

        $account_closed = $this->xchainClientCall(function($xchain) use ($swap, $bot, $account_name) {
            return $xchain->closeAccount($bot['public_address_id'], $account_name, 'default');
        });

        if ($account_closed) {
            BotEventLogger::logAccountClosed($bot, $swap, $closed_actual_balances);
        } else {
            BotEventLogger::logAccountClosedFailed($bot, $swap, $closed_actual_balances);
        }

        return $account_closed;
    }


    ////////////////////////////////////////////////////////////////////////

    protected function xchainClientCall($function) {
        try {
            return $function($this->xchain_client);
        } catch (XChainException $e) {
            EventLog::logError('xchain.call.failed', $e, ['errorName' => $e->getErrorName()]);
            throw $e;
        } catch (Exception $e) {
            EventLog::logError('xchain.call.failed', $e);
            throw $e;
        }
    }

    protected function buildOutputBalancesNeeded($swap) {
        $desired_quantity = $swap['receipt']['quantityOut'];
        $desired_asset    = $swap['receipt']['assetOut'];
        $balances = [$desired_asset => $desired_quantity];

        // add dust and change to non BTC purchases
        if ($desired_asset != 'BTC') {
            $dust_size = SwapProcessor::DEFAULT_REGULAR_DUST_SIZE;

            if ($swap['receipt']['assetIn'] == 'BTC') {
                $change_out = isset($swap['receipt']['changeOut']) ? $swap['receipt']['changeOut'] : 0;
            } else {
                // no change is sent for fiat purchases that are paid with non-BTC tokens
                $change_out = 0;
            }

            $balances['BTC'] = $change_out + $dust_size;
        }

        // add fee to all purchases
        $bot = $swap->bot;
        $balances['BTC'] += $bot['return_fee'];

        return $balances;
    }

}
