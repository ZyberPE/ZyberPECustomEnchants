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
use pocketmine\event\inventory\InventoryTransactionEvent;
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

        $data = $this->getConfig()->get("enchants");
        if(!is_array($data)){
            $this->getLogger()->error("Config 'enchants' section missing or invalid!");
            $data = [];
        }

        $this->enchants = $data;

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /* ===========================
       COMMANDS
    ============================ */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{

        if(count($args) === 0){
            $sender->sendMessage("§cUsage: /$label give <player> <enchant> <level> OR /$label givebook <player> <enchant> <level>");
            return false;
        }

        switch(strtolower($args[0])){

            case "give":
                if(count($args) < 4){
                    $sender->sendMessage("§cUsage: /give <player> <enchant> <level>");
                    return false;
                }

                $target = $this->getServer()->getPlayerExact($args[1]);
                if(!$target){
                    $sender->sendMessage("§cPlayer not found!");
                    return false;
                }

                $enchant = $args[2];
                $level = (int)$args[3];

                $item = $target->getInventory()->getItemInHand();
                $this->applyEnchant($item, $enchant, $level);
                $target->getInventory()->setItemInHand($item);
                $target->sendMessage("§aEnchant '$enchant' applied successfully!");
                $sender->sendMessage("§aApplied enchant to {$target->getName()}");
            break;

            case "givebook":
                if(count($args) < 4){
                    $sender->sendMessage("§cUsage: /givebook <player> <enchant> <level>");
                    return false;
                }

                $target = $this->getServer()->getPlayerExact($args[1]);
                if(!$target){
                    $sender->sendMessage("§cPlayer not found!");
                    return false;
                }

                $enchant = $args[2];
                $level = (int)$args[3];

                $book = $this->createBook($enchant, $level);
                $target->getInventory()->addItem($book);
                $target->sendMessage("§aYou received a book with '$enchant $level'");
                $sender->sendMessage("§aBook given to {$target->getName()}");
            break;

            default:
                $sender->sendMessage("§cUnknown subcommand.");
                return false;
        }

        return true;
    }

    /* ===========================
       BOOK CREATION
    ============================ */
    private function createBook(string $name, int $level): Item{

        $book = VanillaItems::ENCHANTED_BOOK();
        $book->setCustomName("§r§6$name $level");

        $success = $this->enchants[$name]["success"] ?? 100;
        $destroy = $this->enchants[$name]["destroy"] ?? 0;

        $book->setLore([
            "§7Enchantment Book",
            "§eEnchant: §6$name",
            "§eLevel: §6$level",
            "§aSuccess Rate: $success%",
            "§cDestroy Rate: $destroy%",
            "§7Click onto an item to apply"
        ]);

        $nbt = new CompoundTag();
        $nbt->setString("ce_book", $name);
        $nbt->setInt("ce_level", $level);
        $book->setNamedTag($nbt);

        return $book;
    }

    /* ===========================
       APPLY BOOK SYSTEM
    ============================ */
    public function onInventoryTransaction(InventoryTransactionEvent $event): void{

        $transaction = $event->getTransaction();
        $player = $transaction->getSource();

        if(!$player instanceof Player) return;

        foreach($transaction->getActions() as $action){

            $sourceItem = $action->getSourceItem();
            $targetItem = $action->getTargetItem();

            if($sourceItem->getNamedTag()->getTag("ce_book") !== null){

                $name = $sourceItem->getNamedTag()->getString("ce_book");
                $level = $sourceItem->getNamedTag()->getInt("ce_level");

                if($targetItem->isNull()) return;

                $event->cancel();

                $successRate = $this->enchants[$name]["success"] ?? 100;
                $destroyRate = $this->enchants[$name]["destroy"] ?? 0;

                $player->getInventory()->removeItem($sourceItem);

                if(mt_rand(1,100) <= $successRate){
                    $this->applyEnchant($targetItem, $name, $level);
                    $player->getInventory()->addItem($targetItem);
                    $player->sendMessage("§aEnchant applied successfully!");
                }else{
                    if(mt_rand(1,100) <= $destroyRate){
                        $player->sendMessage("§cEnchant failed and item destroyed!");
                    }else{
                        $player->getInventory()->addItem($targetItem);
                        $player->sendMessage("§cEnchant failed!");
                    }
                }
                return;
            }
        }
    }

    /* ===========================
       NBT + LORE SYSTEM
    ============================ */
    private function applyEnchant(Item &$item, string $name, int $level): void{

        $nbt = $item->getNamedTag();
        $list = $nbt->getListTag("ApexCE") ?? new ListTag();

        $tag = new CompoundTag();
        $tag->setString("name", $name);
        $tag->setInt("level", $level);

        $list->push($tag);
        $nbt->setTag("ApexCE", $list);
        $item->setNamedTag($nbt);

        $this->updateLore($item);
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

    private function updateLore(Item &$item): void{

        $lore = [];
        foreach($this->getEnchants($item) as $name => $level){
            $lore[] = "§r§6$name $level";
        }

        $item->setLore($lore);
    }

    /* ===========================
       COMBAT ENCHANTS
    ============================ */
    public function onDamage(EntityDamageByEntityEvent $event): void{

        $damager = $event->getDamager();
        if(!$damager instanceof Player) return;

        $enchants = $this->getEnchants($damager->getInventory()->getItemInHand());

        if(isset($enchants["Lifesteal"])){
            if(mt_rand(1,100) <= $enchants["Lifesteal"] * 10){
                $heal = 1 + $enchants["Lifesteal"];
                $damager->setHealth(min($damager->getMaxHealth(), $damager->getHealth() + $heal));
            }
        }

        if(isset($enchants["Lightning"])){
            if(mt_rand(1,100) <= 15 * $enchants["Lightning"]){
                $world = $damager->getWorld();
                $pos = $event->getEntity()->getPosition();
                $world->addSound($pos, new ThunderSound());
                $world->spawnEntity(new LightningBolt($pos, $world));
            }
        }
    }

    /* ===========================
       BLOCK ENCHANTS (SAFE)
    ============================ */
    public function onBreak(BlockBreakEvent $event): void{

        $player = $event->getPlayer();
        if($player->isCreative()) return;

        $block = $event->getBlock();
        $item = $player->getInventory()->getItemInHand();
        $enchants = $this->getEnchants($item);

        // TreeDestroyer
        if(isset($enchants["TreeDestroyer"]) && $block instanceof Wood){

            $event->cancel();

            for($y = 0; $y < 50; $y++){

                $pos = $block->getPosition()->add(0,$y,0);
                $b = $player->getWorld()->getBlock($pos);

                if(!$b instanceof Wood) break;

                $drops = $b->getDrops($item);
                foreach($drops as $drop){
                    $player->getInventory()->addItem($drop);
                }

                $player->getWorld()->setBlock($pos, VanillaBlocks::AIR());
            }
            return;
        }

        // SAFE Driller
        if(isset($enchants["Driller"]) && $item instanceof Pickaxe){

            $event->cancel();

            $level = $enchants["Driller"];
            $radius = $level >= 3 ? 2 : 1;

            $center = $block->getPosition();
            $world = $player->getWorld();

            $breakableBlocks = [
                "Stone","Granite","Diorite","Andesite",
                "Coal Ore","Iron Ore","Gold Ore","Copper Ore",
                "Diamond Ore","Emerald Ore","Redstone Ore","Lapis Ore",
                "Deepslate","Deepslate Coal Ore","Deepslate Iron Ore",
                "Deepslate Gold Ore","Deepslate Copper Ore","Deepslate Diamond Ore",
                "Deepslate Emerald Ore","Deepslate Redstone Ore","Deepslate Lapis Ore"
            ];

            for($x = -$radius; $x <= $radius; $x++){
                for($z = -$radius; $z <= $radius; $z++){

                    $pos = $center->add($x,0,$z);
                    $target = $world->getBlock($pos);

                    if($target->isAir()) continue;
                    if($target instanceof \pocketmine\block\Bedrock) continue;
                    if(!in_array($target->getName(), $breakableBlocks, true)) continue;

                    $drops = [];
                    try {
                        $drops = $target->getDrops($item);
                    } catch (\Throwable $e){
                        continue;
                    }

                    foreach($drops as $drop){
                        $player->getInventory()->addItem($drop);
                    }

                    $world->setBlock($pos, VanillaBlocks::AIR());
                }
            }
        }
    }
}
