<?php namespace Swapbot\Commands;

use Swapbot\Commands\Command;

class CreateCustomer extends Command {

    var $attributes;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct($attributes)
    {
        $this->attributes = $attributes;
    }

}
