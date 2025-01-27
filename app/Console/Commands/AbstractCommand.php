<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\BotsManager;

/**
 * Class AbstractCommand
 */
abstract class AbstractCommand extends Command
{
    /**
     * @var \Telegram\Bot\Api
     */
    protected $telegram;

    /**
     * @var string
     */
    protected $botName = '';

    /**
     * Create a new command instance.
     * @param BotsManager $botsManager
     * @return void
     */
    public function __construct(BotsManager $botsManager)
    {
        $this->telegram = $botsManager->bot($this->botName);
        parent::__construct();
    }
}
