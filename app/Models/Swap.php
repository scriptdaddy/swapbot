<?php

namespace Swapbot\Models;

use Illuminate\Support\Facades\Config;
use Swapbot\Models\Base\APIModel;
use Swapbot\Models\Data\SwapConfig;
use Swapbot\Models\Data\SwapState;

class Swap extends APIModel {

    protected $api_attributes = ['id', 'txid', 'state', 'receipt', 'created_at', 'updated_at', 'completed_at', ];
    protected $api_attributes_with_bot = ['id', 'txid', 'state', 'receipt', 'created_at', 'updated_at', 'completed_at', 'bot_uuid', 'bot_name', 'bot_username', 'bot_url_slug', ];


    protected $state_machine        = null;

    protected $casts = [
        'definition'   => 'json',
        'receipt'      => 'json',
    ];
    protected $dates = ['completed_at'];

    public function getAddressAttribute() {
        $xchain_notification = $this->transaction['xchain_notification'];
        return $xchain_notification['sources'][0];
    }
    public function getTxidAttribute() {
        $xchain_notification = $this->transaction['xchain_notification'];
        return $xchain_notification['txid'];
    }
    public function getInQtyAttribute() {
        $xchain_notification = $this->transaction['xchain_notification'];
        return $xchain_notification['quantity'];
    }
    public function getInAssetAttribute() {
        $xchain_notification = $this->transaction['xchain_notification'];
        return $xchain_notification['asset'];
    }

    public function transaction() {
        return $this->belongsTo('Swapbot\Models\Transaction');
    }

    public function bot() {
        return $this->belongsTo('Swapbot\Models\Bot');
    }

    public function getBotUuidAttribute() {
        if (array_key_exists('bot_uuid', $this->attributes)) { return $this->attributes['bot_uuid']; }
        return $this->bot['uuid'];
    }
    public function getBotNameAttribute() {
        if (array_key_exists('bot_name', $this->attributes)) { return $this->attributes['bot_name']; }
        return $this->bot['name'];
    }
    public function getBotUsernameAttribute() {
        if (array_key_exists('bot_username', $this->attributes)) { return $this->attributes['bot_username']; }
        return $this->bot['username'];
    }

    public function getBotUrlSlugAttribute() {
        if (array_key_exists('bot_url_slug', $this->attributes)) { return $this->attributes['bot_url_slug']; }
        return $this->bot['url_slug'];
    }


    public function getPublicSwapURL($username=null) {
        if ($username === null) { $username = $this->bot['username']; }
        return Config::get('swapbot.site_host')."/swap/{$username}/{$this['uuid']}";
    }


    public function getSwapConfig() {
        return SwapConfig::createFromSerialized($this['definition']);
    }
    public function getSwapConfigStrategy() {
        return $this['definition']['strategy'];
    }

    // pending swaps are those that have not been processed yet
    public function isPending() {
        return in_array($this['state'], SwapState::allPendingStates());
    }

    public function isReady() {
        return ($this['state'] == SwapState::READY);
    }
    public function isConfirming() {
        return ($this['state'] == SwapState::CONFIRMING);
    }
    public function isOutOfStock() {
        return ($this['state'] == SwapState::OUT_OF_STOCK);
    }
    public function isOutOfFuel() {
        return ($this['state'] == SwapState::OUT_OF_FUEL);
    }
    public function isComplete($state=null) {
        return (($state === null ? $this['state'] : $state) == SwapState::COMPLETE);
    }
    public function isError($state=null) {
        $state = ($state === null ? $this['state'] : $state);
        switch ($state) {
            case SwapState::ERROR:
            case SwapState::PERMANENT_ERROR:
            case SwapState::OUT_OF_STOCK:
            case SwapState::OUT_OF_FUEL:
            case SwapState::INVALIDATED:
                return true;
        }

        return false;
    }

    public function wasSent() {
        switch ($this['state']) {
            case SwapState::SENT:
            case SwapState::COMPLETE:
                return true;
        }

        return false;
    }

    public function stateMachine() {
        if (!isset($this->state_machine)) {
            $this->state_machine = app('Swapbot\Statemachines\SwapStateMachineFactory')->buildStateMachineFromSwap($this);
        }
        return $this->state_machine;
    }

}
