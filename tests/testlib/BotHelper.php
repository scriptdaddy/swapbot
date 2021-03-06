<?php

use Illuminate\Contracts\Validation\ValidationException;
use Illuminate\Foundation\Bus\DispatchesCommands;
use Rhumsaa\Uuid\Uuid;
use Swapbot\Commands\CreateBot;
use Swapbot\Http\Requests\Bot\Validators\CreateBotValidator;
use Swapbot\Models\Bot;
use Swapbot\Models\Data\BotPaymentState;
use Swapbot\Models\Data\BotState;
use Swapbot\Models\Data\IncomeRuleConfig;
use Swapbot\Models\Data\RefundConfig;
use Swapbot\Models\Data\SwapConfig;
use Swapbot\Repositories\BotRepository;
use Swapbot\Util\Slug\Slugifier;

class BotHelper  {

    static $UNIQUE_SLUG_ID = 1;

    use DispatchesCommands;

    function __construct(BotRepository $bot_repository, CreateBotValidator $create_bot_validator) {
        $this->bot_repository = $bot_repository;
        $this->create_bot_validator = $create_bot_validator;
    }


    public function sampleBotVars() {
        return [
            'name'                        => 'Sample Bot One',
            'url_slug'                    => 'sample-bot-one',
            'description'                 => 'The bot description goes here.',
            'active'                      => false,
            'hash'                        => '',
            'address'                     => null,
            'public_address_id'           => null,
            'public_receive_monitor_id'   => null,
            'public_send_monitor_id'      => null,

            'payment_plan'                => 'monthly001',
            'payment_address'             => null,
            'payment_address_id'          => null,
            'payment_receive_monitor_id'  => null,
            'payment_send_monitor_id'     => null,

            'balances'                    => null,
            'all_balances_by_type'        => null,

            'balances_updated_at'         => null,
            'blacklist_addresses'         => ['1JY6wKwW5D5Yy64RKA7rDyyEdYrLSD3J6B'],
            'whitelist_addresses'         => [],
            'whitelist_uuid'              => null,
            'return_fee'                  => 0.0001,
            'confirmations_required'      => 2,
            'state'                       => BotState::BRAND_NEW,
            'payment_state'               => BotPaymentState::NONE,

            'status_details'              => null,

            'background_image_id'         => null,
            'logo_image_id'               => null,
            'background_overlay_settings' => '',

            'shutdown_block'              => null,
            'shutdown_address'            => null,

            'swaps'                       => [
                [
                    'in'       => 'BTC',
                    'out'      => 'LTBCOIN',
                    'strategy' => 'rate',
                    'rate'     => 15000000,
                    'min'      => 0,
                ],
            ],

            'income_rules'                => [
                [
                    'asset'         => 'BTC',
                    'minThreshold'  => 10.0,
                    'paymentAmount' => 2.0,
                    'address'       => '1JY6wKwW5D5Yy64RKA7rDyyEdYrLSD3J6B',
                ],
            ],

            'refund_config'               => [
                [
                    'refundAfterBlocks'   => 6,
                ],
            ],

            'swap_rules'                  => null,

            'registered_with_tokenpass'   => false,

        ];
    }

    public function newBotInMemory() {
        $sample_bot_vars = $this->sampleBotVars();
        $create_vars = app('Swapbot\Http\Requests\Bot\Transformers\BotTransformer')->santizeAttributes($sample_bot_vars, $this->create_bot_validator->getRules());

        $create_vars['state'] = $sample_bot_vars['state'];

        return new Bot($create_vars);
    }

    public function sampleBotVarsForAPI() {
        $out = [];
        $sample_bot_vars = $this->sampleBotVars();
        foreach (array_keys($this->create_bot_validator->getRules()) as $snake_field_name) {
            if (isset($sample_bot_vars[$snake_field_name])) {
                $out[camel_case($snake_field_name)] = $sample_bot_vars[$snake_field_name];
            }
        }

        // add swaps, blacklist addresses, income rules and refundConfig
        $out['swaps']              = $sample_bot_vars['swaps'];
        $out['blacklistAddresses'] = $sample_bot_vars['blacklist_addresses'];
        $out['whitelistAddresses'] = $sample_bot_vars['whitelist_addresses'];
        $out['incomeRules']        = $sample_bot_vars['income_rules'];
        $out['refundConfig']       = $sample_bot_vars['refund_config'];
        
        return $out;
    }

    public function getSampleBot($user) {
        $bots = $this->bot_repository->findByUser($user)->toArray();
        $bot = $bots ? $bots[0] : null;
        if (!$bot) {
            $bot = $this->newSampleBotWithUniqueSlug($user);
        }
        return $bot;
    }

    public function newSampleBotWithUniqueSlug($user=null, $bot_vars=[]) {
        if (!isset($bot_vars['url_slug'])) {
            $bot_vars['url_slug'] = 'sample-bot-'.sprintf('%03d', self::$UNIQUE_SLUG_ID++);
        }

        return $this->newSampleBot($user, $bot_vars);
    }

    // creates a bot
    //   directly in the repository (no validation)
    public function newSampleBot($user=null, $bot_vars=[]) {
        $attributes = array_replace_recursive($this->sampleBotVars(), $bot_vars);
        if ($user == null) {
            $user = app()->make('UserHelper')->getSampleUser();
        }
        $attributes['user_id'] = $user['id'];

        // create a slug
        if (!isset($bot_vars['url_slug']) AND isset($bot_vars['name'])) {
            $new_slug = Slugifier::slugify($bot_vars['name']);
            if (strlen($new_slug)) {
                $attributes['url_slug'] = $new_slug;
            }
        }

        try {
            if (!isset($attributes['uuid'])) {
                $uuid = Uuid::uuid4()->toString();
                $attributes['uuid'] = $uuid;
            }

            if (isset($attributes['swaps'])) {
                $swap_configs = [];
                foreach ($attributes['swaps'] as $swap_config_data) {
                    $swap_configs[] = SwapConfig::createFromSerialized($swap_config_data);
                }
                $attributes['swaps'] = $swap_configs;
            }

            if (isset($attributes['income_rules'])) {
                $swap_configs = [];
                foreach ($attributes['income_rules'] as $income_rule_data) {
                    $income_rules[] = IncomeRuleConfig::createFromSerialized($income_rule_data);
                }
                $attributes['income_rules'] = $income_rules;
            }

            if (isset($attributes['refund_config'])) {
                $attributes['refund_config'] = RefundConfig::createFromSerialized($attributes['refund_config']);
            }

            // sample address
            if (!isset($attributes['address'])) { $attributes['address'] = '1xxxxxxxxxxxxxxxxxxxxxxxx'. substr(md5(uniqid()),-8); }

            $bot_model = $this->bot_repository->create($attributes);
            return $bot_model;
        } catch (ValidationException $e) {
            throw new Exception("ValidationException: ".json_encode($e->errors()->all(), 192), $e->getCode());
        }
    }

    public function sampleAddressVars($attributes = []) {

        // sample address IDs
        if (!isset($attributes['public_address_id'])) { $attributes['public_address_id'] = '11111111-1111-1111-1111-'. substr(md5(uniqid()),-12); }
        if (!isset($attributes['public_receive_monitor_id'])) { $attributes['public_receive_monitor_id'] = '11111111-1111-1111-2222-'. substr(md5(uniqid()),-12); }
        if (!isset($attributes['public_send_monitor_id'])) { $attributes['public_send_monitor_id'] = '11111111-1111-1111-3333-'. substr(md5(uniqid()),-12); }

        return $attributes;
    }



    // uses a command to validate and sanitize the input
    public function newSampleBotWothCommand($user=null, $bot_vars=[]) {
        $attributes = array_replace_recursive($this->sampleBotVars(), $bot_vars);
        if ($user == null) {
            $user = app()->make('UserHelper')->getSampleUser();
        }
        $attributes['user_id'] = $user['id'];

        try {
            $uuid = Uuid::uuid4()->toString();
            $attributes['uuid'] = $uuid;
            $this->dispatch(new CreateBot($attributes, $user));

            // now load the model
            return $this->bot_repository->findByUuid($uuid);
        } catch (ValidationException $e) {
            throw new Exception("ValidationException: ".json_encode($e->errors()->all(), 192), $e->getCode());
        }
    }

}
