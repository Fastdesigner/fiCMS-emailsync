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
		$result = \ficms\Files::updateJson(self::path($name),$update,true);
		if ($result !== false) @chmod(self::path($name),0600);
		return $result;
	}

	public static function write(string $name, $content, bool $secure = false): bool {
		$result = \ficms\Files::writeContent(self::path($name),$content,true,$secure);
		if ($result && $secure) @chmod(self::path($name),0600);
		return $result;
	}

	public static function delete(string $name, bool $cleanup = true): bool {
		return \ficms\Files::delete(self::path($name),$cleanup);
	}
}
