<?php

namespace falkirks\minereset\command;


use falkirks\minereset\MineReset;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\Plugin;

class MineCommand extends Command {

    /** @var MineReset  */
    protected $api;
    /** @var  SubCommand[] */
    protected $subCommands;

    public function __construct(MineReset $api){
        parent::__construct("mine", "Mine reset command", "/mine <create|set|list|reset|reset-all|destroy|report> <name> [parameters]");
        $this->setPermission("mine.command");
        $this->api = $api;
        $this->subCommands = [];
    }

    /**
     * @param CommandSender $sender
     * @param string $commandLabel
     * @param string[] $args
     *
     * @return mixed
     */
    public function execute(CommandSender $sender, string $commandLabel, array $args): mixed
    {
        if(!$this->testPermission($sender)){
			return null;
		}
        if(count($args) > 0 && array_key_exists($args[0], $this->subCommands)){
            return $this->subCommands[array_shift($args)]->execute($sender, $commandLabel, $args);
        }
        else{
            $sender->sendMessage($this->getUsage());
            return null;
        }
    }

    /**
     * @return Plugin
     */
    public function getPlugin(): Plugin{
        return $this->api;
    }

    public function registerSubCommand(string $name, SubCommand $command, $aliases = []){
        $this->subCommands[$name] = $command;

        foreach ($aliases as $alias){
            if(!isset($this->subCommands[$alias])){
                $this->registerSubCommand($alias, $command);
            }
        }
    }
}
