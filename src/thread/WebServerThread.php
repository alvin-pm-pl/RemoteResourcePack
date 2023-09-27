<?php

declare(strict_types=1);

namespace alvin0319\RemoteResourcePack\thread;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Socket\Server;
use pocketmine\thread\Thread;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_diff;
use function file_get_contents;
use function in_array;
use function is_file;
use function pathinfo;
use function scandir;
use function str_replace;
use const PATHINFO_EXTENSION;

final class WebServerThread extends Thread{

	private bool $interrupted = false;

	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly string $path,
		private readonly string $composerPath,
		private readonly string $logPath
	){
	}

	protected function onRun() : void{
		require $this->composerPath;
		Loop::run(function(){
			$sockets = [Server::listen($this->host . ":" . $this->port)];

			$logger = new FileLogger($this->logPath);

			$router = new Router();
			foreach(array_diff(Utils::assumeNotFalse(scandir($this->path)), [".", ".."]) as $file){
				$realPath = Path::join($this->path, $file);
				if(is_file($realPath) && in_array(pathinfo($realPath, PATHINFO_EXTENSION), ["mcpack", "zip"])){
					$router->addRoute("GET", "/" . str_replace([".zip", ".mcpack"], "", $file), new CallableRequestHandler(function(Request $request) use ($realPath, $logger) : Response{
						$logger->debug("Serving $realPath, Requested by: " . $request->getClient()->getRemoteAddress()->toString());
						return new Response(Status::OK, ["content-type" => "application/zip-archive"], file_get_contents($realPath));
					}));
				}
			}

			$server = new HttpServer($sockets, $router, $logger);

			try{
				yield $server->start();
			}catch(MultiReasonException $e){
//				$this->interrupted = true;
			}

			Loop::repeat(50, function(string $watcherId) use ($server){
				if($this->interrupted){
					Loop::cancel($watcherId);
					yield $server->stop();
				}
			});
		});
	}

	public function interrupt() : void{
		$this->synchronized(function() : void{
			$this->interrupted = true;
			$this->notify();
		});
	}
}
