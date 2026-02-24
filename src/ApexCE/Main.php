<?php

declare(strict_types=1);

namespace ApexCE;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\item\VanillaItems;
use pocketmine\item\Item;
use pocketmine\item\Pickaxe;
use pocketmine\block\Wood;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\world\sound\ThunderSound;
use pocketmine\entity\LightningBolt;

final class Main extends PluginBase implements Listener{

    private array $enchants = [];

    protected function onEnable(): void{
        $this->saveDefaultConfig();
        $this->enchants = $this->getConfig()->get("enchants");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /* --------------------------------
       COMMAND SYSTEM
    -------------------------------- */

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{

        if(!$sender instanceof Player) return false;
        if(!isset($args[0])) return false;

        switch(strtolower($args[0])){

            case "give":
                if(!isset($args[1], $args[2], $args[3])) return false;

                $target = $this->getServer()->getPlayerExact($args[1]);
                if(!$target) return false;

                $enchant = $args[2];
                $level = (int)$args[3];

                $item = $target->getInventory()->getItemInHand();
                $this->applyEnchant($item, $enchant, $level);
                $target->getInventory()->setItemInHand($item);
                $target->sendMessage("§aEnchant applied!");
            break;

            case "givebook":
                if(!isset($args[1], $args[2], $args[3])) return false;

                $target = $this->getServer()->getPlayerExact($args[1]);
                if(!$target) return false;

                $book = $this->createBook($args[2], (int)$args[3]);
                $target->getInventory()->addItem($book);
            break;
        }

        return true;
    }

    /* --------------------------------
       ENCHANT STORAGE (NBT)
    -------------------------------- */

    private function applyEnchant(Item &$item, string $name, int $level): void{
        $nbt = $item->getNamedTag();
        $list = $nbt->getListTag("ApexCE") ?? new ListTag();

        $tag = new CompoundTag();
        $tag->setString("name", $name);
        $tag->setInt("level", $level);

        $list->push($tag);
        $nbt->setTag("ApexCE", $list);
        $item->setNamedTag($nbt);
    }

    private function getEnchants(Item $item): array{
        $nbt = $item->getNamedTag();
        $list = $nbt->getListTag("ApexCE");
        if(!$list) return [];

        $enchants = [];
        foreach($list as $tag){
            $enchants[$tag->getString("name")] = $tag->getInt("level");
        }
        return $enchants;
    }

    /* --------------------------------
       BOOK SYSTEM
    -------------------------------- */

    private function createBook(string $name, int $level): Item{
        $book = VanillaItems::ENCHANTED_BOOK();
        $book->setCustomName("§6$name $level");

        $lore = [
            "§7Success: ".$this->enchants[$name]["success"]."%",
            "§cDestroy: ".$this->enchants[$name]["destroy"]."%"
        ];
        $book->setLore($lore);

        $nbt = new CompoundTag();
        $nbt->setString("ce_name", $name);
        $nbt->setInt("ce_level", $level);
        $book->setNamedTag($nbt);

        return $book;
    }

    /* --------------------------------
       COMBAT ENCHANTS
    -------------------------------- */

    public function onDamage(EntityDamageByEntityEvent $event): void{
        $damager = $event->getDamager();
        if(!$damager instanceof Player) return;

        $enchants = $this->getEnchants($damager->getInventory()->getItemInHand());

        // Lifesteal
        if(isset($enchants["Lifesteal"])){
            $level = $enchants["Lifesteal"];
            if(mt_rand(1,100) <= $level * 10){
                $heal = 1 + $level;
                $damager->setHealth(min($damager->getMaxHealth(), $damager->getHealth() + $heal));
            }
        }

        // Lightning
        if(isset($enchants["Lightning"])){
            $level = $enchants["Lightning"];
            if(mt_rand(1,100) <= 15 * $level){
                $world = $damager->getWorld();
                $pos = $event->getEntity()->getPosition();
                $world->addSound($pos, new ThunderSound());
                $world->spawnEntity(new LightningBolt($pos, $world));
            }
        }
    }

    /* --------------------------------
       BLOCK ENCHANTS
    -------------------------------- */

    public function onBreak(BlockBreakEvent $event): void{
        $player = $event->getPlayer();
        if($player->isCreative()) return;

        $block = $event->getBlock();
        $item = $player->getInventory()->getItemInHand();
        $enchants = $this->getEnchants($item);

        /* ---- TreeDestroyer ---- */
        if(isset($enchants["TreeDestroyer"]) && $block instanceof Wood){

            $event->cancel();

            for($y = 0; $y < 50; $y++){
                $pos = $block->getPosition()->add(0,$y,0);
                $b = $player->getWorld()->getBlock($pos);
                if(!$b instanceof Wood) break;

                foreach($b->getDrops($item) as $drop){
                    $player->getInventory()->addItem($drop);
                }

                $player->getWorld()->setBlock($pos, VanillaBlocks::AIR());
            }
            return;
        }

        /* ---- Driller ---- */
        if(isset($enchants["Driller"]) && $item instanceof Pickaxe){

            $event->cancel();

            $level = $enchants["Driller"];
            $radius = $level >= 3 ? 2 : 1; // lvl3 = 5x5, others 3x3

            $center = $block->getPosition();
            $world = $player->getWorld();

            for($x = -$radius; $x <= $radius; $x++){
                for($z = -$radius; $z <= $radius; $z++){

                    $pos = $center->add($x, 0, $z);
                    $target = $world->getBlock($pos);

                    if($target->getHardness() > 0){

                        foreach($target->getDrops($item) as $drop){
                            $player->getInventory()->addItem($drop);
                        }

                        $world->setBlock($pos, VanillaBlocks::AIR());
                    }
                }
            }
        }
    }
}
