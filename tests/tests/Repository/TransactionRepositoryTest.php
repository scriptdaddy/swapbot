<?php

use \PHPUnit_Framework_Assert as PHPUnit;

class TransactionRepositoryTest extends TestCase {

    protected $use_database = true;

    public function testTransactionRepository()
    {
        $create_model_fn = function() {
            return $this->app->make('TransactionHelper')->newSampleTransaction();
        };
        $helper = new RepositoryTestHelper($create_model_fn, $this->app->make('Swapbot\Repositories\TransactionRepository'));
        $helper->use_uuid = false;

        $helper->testLoad();
        $helper->cleanup()->testUpdate(['confirmations' => 2]);
        $helper->cleanup()->testDelete();
        // $helper->cleanup()->testFindAll();
    }

    public function testFindTransactionByTXIDAndBotID()
    {
        $bot1 = app()->make('BotHelper')->newSampleBot();
        $bot2 = app()->make('BotHelper')->newSampleBot();

        $tx_helper = $this->app->make('TransactionHelper');
        $tx1 = $tx_helper->newSampleTransaction($bot1, ['txid' => 'tx001']);
        $tx2 = $tx_helper->newSampleTransaction($bot1, ['txid' => 'tx002']);

        $tx3 = $tx_helper->newSampleTransaction($bot2, ['txid' => 'tx001']);

        $tx_repository = $this->app->make('Swapbot\Repositories\TransactionRepository');
        $loaded_tx1 = $tx_repository->findByTransactionIDAndBotID('tx001', $bot1['id']);
        PHPUnit::assertEquals($tx1['id'], $loaded_tx1['id']);
        $loaded_tx2 = $tx_repository->findByTransactionIDAndBotID('tx002', $bot1['id']);
        PHPUnit::assertEquals($tx2['id'], $loaded_tx2['id']);

        // bot 2
        $loaded_tx3 = $tx_repository->findByTransactionIDAndBotID('tx001', $bot2['id']);
        PHPUnit::assertEquals($tx3['id'], $loaded_tx3['id']);
    }



}