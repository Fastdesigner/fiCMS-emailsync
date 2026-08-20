<?php

namespace emailsync;

final class State {
	public static function path(string $name = ''): string {
		$name = trim(str_replace('\\','/',$name),'/');
		foreach (explode('/',$name) as $part) if ($part === '..' || ($part !== '' && !preg_match('/^[a-z0-9_.-]+$/i',$part))) throw new \InvalidArgumentException('emailsync_state_path_invalid');
		$root = 'system/plugins/fiCMS-emailsync/state';
		return $name === '' ? $root : $root.'/'.$name;
	}

	public static function read(string $name): array {
		return \ficms\Files::readJson(self::path($name));
	}

	public static function update(string $name, callable $update): array|false {
		return \ficms\Files::updateJson(self::path($name),$update,true);
	}

	public static function write(string $name, $content, bool $secure = false): bool {
		return \ficms\Files::writeContent(self::path($name),$content,true,$secure);
	}

	public static function delete(string $name, bool $cleanup = true): bool {
		return \ficms\Files::delete(self::path($name),$cleanup);
	}
}
