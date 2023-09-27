<?php

declare(strict_types=1);

namespace alvin0319\RemoteResourcePack;

use alvin0319\RemoteResourcePack\thread\WebServerThread;
use FilesystemIterator;
use pocketmine\event\EventPriority;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Internet;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_diff;
use function basename;
use function file_exists;
use function getenv;
use function in_array;
use function is_dir;
use function is_file;
use function mkdir;
use function pathinfo;
use function scandir;
use function sha1_file;
use function str_ends_with;
use function trim;
use function yaml_emit;
use function yaml_parse;
use const PATHINFO_EXTENSION;

final class Loader extends PluginBase{

	/**
	 * @var array <string, string> $resourcePacks uuid => url
	 */
	private array $resourcePacks = [];

	private WebServerThread $thread;

	protected function onEnable() : void{
		$this->saveDefaultConfig();
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
		$repack = false;
		if(is_dir($dir = Path::join($this->getDataFolder(), "packs"))){
			if(file_exists($file = Path::join($this->getDataFolder(), "hash.yml"))){
				$content = Utils::assumeNotFalse(yaml_parse(Filesystem::fileGetContents($file)));
				foreach(array_diff(scandir(Path::join($this->getServer()->getResourcePackManager()->getPath())), [".", ".."]) as $f){
					$p = Path::join($this->getServer()->getResourcePackManager()->getPath(), $f);
					if(is_file($p) && in_array(pathinfo($p, PATHINFO_EXTENSION), ["zip", "mcpack"])){
						$hash = $content[basename($p)] ?? null;
						if($hash === null || $hash !== sha1_file($p)){
							$repack = true;
							break;
						}
					}
				}
			}else{
				$repack = true;
			}
		}else{
			$repack = true;
		}
		if($repack){
			Filesystem::recursiveUnlink($dir);
			mkdir($dir);
			$hashes = [];
			foreach($this->getServer()->getResourcePackManager()->getResourceStack() as $resourcePack){
				if($resourcePack instanceof ZippedResourcePack){
					$path = $resourcePack->getPath();
					$fileName = basename($path);
					$hashes[$fileName] = sha1_file($path);
					$this->extractAndArchive($resourcePack);
				}
			}
			$file = Path::join($this->getDataFolder(), "hash.yml");
			Filesystem::safeFilePutContents($file, yaml_emit($hashes));
		}
		$downloadableIP = getenv("RP_REMOTE_DEV") !== false ? "127.0.0.1" : Internet::getIP();
		$port = $this->getConfig()->getNested('http.port', 8080);
		foreach($this->getServer()->getResourcePackManager()->getResourceStack() as $resourcePack){
			$this->resourcePacks[$resourcePack->getPackId()] = "http://$downloadableIP:$port" . "/" . $resourcePack->getPackId();
			$this->getLogger()->debug("Registered resource pack {$resourcePack->getPackId()} to http://$downloadableIP:$port" . "/" . $resourcePack->getPackId());
		}
		$this->thread = new WebServerThread(
			$this->getConfig()->getNested('http.host', '0.0.0.0'),
			$this->getConfig()->getNested('http.port', 8080),
			$dir,
			Path::join($this->getFile(), "vendor/autoload.php"),
			Path::join($this->getDataFolder(), "server.log")
		);
		$this->thread->start();
	}

	private function extractAndArchive(ZippedResourcePack $resourcePack) : void{
		$file = $resourcePack->getPath();
		if(file_exists($file)){
			$zip = new \ZipArchive();
			$zip->open($file);
			$dest = Path::join($this->getDataFolder(), "packs", $resourcePack->getPackId());
			$zip->extractTo(Path::join($dest, $resourcePack->getPackId()));
			$zip->close();
			$newZip = new \ZipArchive();
			$newZip->open($dest . ".zip", \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
			$this->recursiveZipDir($newZip, $dest);
			$newZip->close();
			Filesystem::recursiveUnlink($dest);
		}
	}

	protected function onDisable() : void{
		$this->thread->interrupt();
	}

	public function recursiveZipDir(\ZipArchive $zip, string $dir, string $tempDir = "") : void{
		$dir = Filesystem::cleanPath($dir);
		$tempDir = Filesystem::cleanPath($tempDir);
		if(!str_ends_with($dir, "/")){
			$dir .= "/";
		}

		if(trim($tempDir) !== "" && !str_ends_with($tempDir, "/")){
			$tempDir .= "/";
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir),
			\RecursiveIteratorIterator::LEAVES_ONLY | FilesystemIterator::SKIP_DOTS
		);

		/** @var \SplFileInfo $file */
		foreach($files as $file){
			if(in_array($file->getFilename(), ['.', '..'], true)){
				continue;
			}
			$filePath = Path::join($dir, $file->getFilename());
			$zipPath = Path::join($tempDir, $file->getFilename());

			if(!$file->isDir()){
				$zip->addFile($filePath, $zipPath);
			}else{
				$zip->addEmptyDir($zipPath);
				$this->recursiveZipDir($zip, $filePath, $zipPath . '/');
			}
		}
	}
}
