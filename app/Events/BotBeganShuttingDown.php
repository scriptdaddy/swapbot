<?php

namespace Swapbot\Events;

use Swapbot\Events\Event;
use Swapbot\Models\Bot;

class BotBeganShuttingDown extends Event
{
    var $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }
}
