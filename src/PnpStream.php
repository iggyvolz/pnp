<?php

namespace iggyvolz\Pnp;


class PnpStream
{
    private const NAME = "pnp";
    /**
     * @var array<string,array{0:resource,1:null|string,2:int,3:array<string,array{0:int,1:int}>}>
     * name => [stream, filter, globalOffset, file => [start, len]]
     */
    private static array $streams = [];

    /**
     * @var resource Currently opened resource
     */
    private mixed $stream;
    public function __construct()
    {
        $this->stream = fopen("php://memory", "rw");
    }

    private static function realpath(string $path): string {
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }
    public function stream_open(string $path): bool
    {
        foreach(self::$streams as $file => $data) {
            if(str_starts_with($path, "pnp://$file/")) {
                [$stream, $filter, $globalOffset, $manifest] = $data;
                $file = '/' . self::realpath(substr($path, strlen("pnp://$file/")));
                if(array_key_exists($file, $manifest)) {
                    [$start, $length] = $manifest[$file];
                    fseek($stream, $globalOffset + $start);
                    $conts = fread($stream, $length);
                    if($filter) $conts = $filter($conts);
                    ftruncate($this->stream, strlen($conts));
                    rewind($this->stream);
                    fwrite($this->stream, $conts);
                    rewind($this->stream);
                    return true;
                } else {
                    echo implode("\n", array_keys($manifest));
                }
            }
        }
        return false;
    }

    public function stream_read(int $count): string
    {
        return fread($this->stream, $count);
    }

    public function stream_tell(): int
    {
        return ftell($this->stream);
    }

    public function stream_eof(): bool
    {
        return feof($this->stream);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        fseek($this->stream, $offset, $whence);
        return true;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        switch($option) {
            case STREAM_OPTION_BLOCKING:
                stream_set_blocking($this->stream, $arg1 === 1);
                return true;
            case STREAM_OPTION_READ_TIMEOUT:
                stream_set_timeout($this->stream, $arg1, $arg2);
                return true;
            case STREAM_OPTION_WRITE_BUFFER:
                return true;
        }
        return false;
    }

    public function stream_stat(): array
    {
        return fstat($this->stream);
    }

    public function url_stat(string $path, int $flags): false|array
    {
        $exists = false;
        foreach(self::$streams as $file => $data) {
            if (str_starts_with($path, "pnp://$file/")) {
                [$stream, $filter, $globalOffset, $manifest] = $data;
                $file = '/' . self::realpath(substr($path, strlen("pnp://$file/")));
                if(array_key_exists($file, $manifest)) {
                    $exists = true;
                    break;
                }
            }
        }
        if(!$exists) return false;
        $entries = [
            "dev" => 0,
            "ino" => 0,
            "mode" => 0444,
            "nlink" => 0,
            "uid" => 0,
            "gid" => 0,
            "rdev" => 0,
            "size" => 0,
            "atime" => 0,
            "mtime" => 0,
            "ctime" => 0,
            "blksize" => 0,
            "blocks" => 0,
        ];
        return [...$entries, ...array_values($entries)];
    }

    public static function register(): void
    {
        stream_wrapper_register(self::NAME, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::NAME);
    }

    public static function load(string $pnpFile, mixed $stream, ?string $filter, int $globalOffset, array $manifest): void
    {
        self::$streams[$pnpFile] = [$stream, $filter, $globalOffset, $manifest];
    }
}
