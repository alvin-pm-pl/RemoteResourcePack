<?php

declare(strict_types=1);

namespace alvin0319\RemoteResourcePack;

use pocketmine\event\EventPriority;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\utils\Config;
use Symfony\Component\Filesystem\Path;
use function file_exists;

final class Loader extends PluginBase{

	/**
	 * @var array <string, string> $resourcePacks uuid => url
	 */
	private array $resourcePacks = [];

	protected function onEnable() : void{
		$this->saveResource("resource_packs.yml");
		$config = new Config(Path::join($this->getDataFolder(), "resource_packs.yml"), Config::YAML);
		foreach($config->get("resource_packs", []) as $fileName => $url){
			$uuid = $this->findMatchResourcePack($fileName);
			if($uuid !== null){
				$this->resourcePacks[$uuid] = $url;
			}else{
				$this->getLogger()->notice("Failed to find resource pack $fileName");
			}
		}
		$this->getServer()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $event) : void{
			foreach($event->getPackets() as $packet){
				if($packet instanceof ResourcePacksInfoPacket){
					foreach($packet->resourcePackEntries as $entry){
						if(isset($this->resourcePacks[$entry->getPackId()])){
							$url = $this->resourcePacks[$entry->getPackId()];
							$packet->cdnUrls[$entry->getPackId() . "_" . $entry->getVersion()] = $url;
						}
					}
				}
			}
		}, EventPriority::NORMAL, $this);
	}

	private function findMatchResourcePack(string $fileName) : ?string{
		if(!file_exists($file = Path::join($this->getServer()->getResourcePackManager()->getPath(), $fileName))){
			return null;
		}
		$zipResourcePack = new ZippedResourcePack($file);
		return $zipResourcePack->getPackId();
	}
}