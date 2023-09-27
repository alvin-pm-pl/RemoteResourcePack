<?php

declare(strict_types=1);

namespace alvin0319\RemoteResourcePack\thread;

use Psr\Log\AbstractLogger;
use function fopen;
use function fwrite;

final class FileLogger extends AbstractLogger{

	private $handle;

	public function __construct(string $filePath){
		$this->handle = fopen($filePath, "a+");
	}

	public function log($level, \Stringable|string $message, array $context = []) : void{
		fwrite($this->handle, "[$level] $message\n");
	}
}
