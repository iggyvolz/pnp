<?php

namespace iggyvolz\Pnp;

enum Compression
{
    case NONE;
    // Yes, not a compression - but it fixes PHP's parse errors
    case BASE64;
    case GZIP;
    case BZIP;


    public function compress(string $contents): string
    {
        return match($this) {
            self::NONE => $contents,
            self::BASE64 => \base64_encode($contents),
            self::GZIP => \gzencode($contents),
            self::BZIP => \bzcompress($contents),
        };
    }

    public function decompressCode(string $contents): string
    {
        return match($this) {
            self::NONE => $contents,
            self::BASE64 => "\base64_decode($contents)",
            self::GZIP => "gzdecode($contents)",
            self::BZIP => "bzdecompress($contents)",
        };
    }

    public function decompress(string $contents): string
    {
        return match($this) {
            self::NONE => $contents,
            self::BASE64 => \base64_decode($contents),
            self::GZIP => \gzdecode($contents),
            self::BZIP => \bzdecompress($contents),
        };
    }

    public function guard(): void
    {
        switch($this) {
            case self::NONE:
            case self::BASE64:
                break;
            case self::GZIP:
                if(!extension_loaded('zlib')) {
                    throw new \RuntimeException('Zlib extension required');
                }
                break;
            case self::BZIP:
                if(!extension_loaded('bz2')) {
                    throw new \RuntimeException('Bz2 extension required');
                }
                break;
        };
    }

    public function guardCode(): string
    {
        return match($this) {
            self::NONE, self::BASE64 => "",
            self::GZIP => "if(!extension_loaded('zlib')) throw new \\RuntimeException('Zlib extension required');",
            self::BZIP => "if(!extension_loaded('bz2')) throw new \\RuntimeException('Bz2 extension required');",
        };
    }
}