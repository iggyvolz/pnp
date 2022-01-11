<?php

namespace iggyvolz\Pnp;

use Closure;
use Latte\Engine;
use PhpToken;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Pnp extends Command
{
    protected static $defaultName = "run";

    /**
     * @param mixed $conts
     * @return string
     */
    public static function minify(string $conts): string
    {
        if(true || !in_array("tokenizer", get_loaded_extensions())) {
            return $conts;
        }
        $tokens = \PhpToken::tokenize($conts, TOKEN_PARSE);
        $result = "";
        // First pass - collapse all whitespace to a single space
        foreach($tokens as $token) {
            if ($token->is(T_OPEN_TAG)) {
                $result .= "<?php ";
            } elseif ($token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                // We may need to keep this - figure it out on the second pass
                if(!str_ends_with($result, " ")) {
                    $result .= " ";
                }
            } else {
                $result .= $token;
            }
        }
        // Second pass - see if whitespace is needed
        $tokens = PhpToken::tokenize($result, TOKEN_PARSE);
        $result = "";
        foreach($tokens as $i => $token) {
            if($token->is(T_WHITESPACE)) {
                $next = $tokens[$i + 1] ?? null;
                // No need for whitespace at end
                if (is_null($next)) continue;
                // Check the previous and next token to see if they'll mush together
                if (
                    preg_match("/[a-zA-Z0-9_\x7f-\xff]$/", $result) &&
                    preg_match("/^[a-zA-Z0-9_\x7f-\xff]/", $next)
                ) {
                    // Last token ends with an identifier character, next character is an identifier character
                    // So we actually need the space
                    $result .= " ";
                }
            } else {
                    $result .= $token;
            }
        }
        return $result;
    }

    protected function configure(): void
    {
        $this->addArgument("output", mode: InputArgument::REQUIRED, description: "Output file");
        $this->addOption("bootstrap", "b", mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, description: "Script(s) to bootstrap (php://stdin for stdin)");
        $this->addOption("vendor", mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, description: "Path to /vendor directory(ies) for project");
        $this->addOption("file", mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, description: "Additional files to include");
        $this->addOption("shebang", mode: InputOption::VALUE_REQUIRED, description: "Shebang to add to the application", default: "#!/usr/bin/env php");
        $this->addOption("compression", "c", mode: InputOption::VALUE_REQUIRED, description: "Compression mode (none, gzip, bzip2, base64)", default: "none");
        $this->addOption("streamable", "s", mode: InputOption::VALUE_NONE, description: "Use an array rather than __halt_compiler() (ex. for curl|php)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bootstraps = [];
        $inputBootstraps = $input->getOption("bootstrap");
        if(empty($inputBootstraps)) {
            $output->writeln("At least one bootstrap file is required");
            return self::FAILURE;
        }
        $vendorDirs = $input->getOption("vendor");
        $files = $input->getOption("file") ?? [];
        foreach($vendorDirs as $vendorDir) {
            $files = array_values([...$files, ...(require $vendorDir . "/composer/autoload_classmap.php")]);
            foreach(file_exists($vendorDir . "/composer/autoload_files.php") ? require $vendorDir . "/composer/autoload_files.php" : [] as $file) {
                $bootstraps[] = $file;
                $files[] = $file;
            }
            $files[] = "$vendorDir/autoload.php";
            foreach(scandir($vendorDir . "/composer") as $file) {
                if($file[0] === ".") continue;
                $files[] = $vendorDir . "/composer/$file";
            }
        }
        // Prepend vendor bootstraps before bootstrap script
        $bootstraps = [...$bootstraps, ...$inputBootstraps];
        try {
            $filter = match ($input->getOption("compression")) {
                "none" => self::FILTER_NONE,
                "gzip" => self::FILTER_GZIP,
                "bzip" => self::FILTER_BZIP,
                "base64" => self::FILTER_BASE64,
                default => throw new \RuntimeException("Invalid option for compression")
            };
        } catch(\RuntimeException $e) {
            $output->writeln($e->getMessage());
            return self::FAILURE;
        }
        $output = $input->getArgument("output");
        if($output === "-") $output = "php://stdout";
        self::process(array_combine($files, array_map(Closure::fromCallable('file_get_contents'), $files)), $bootstraps, fopen($output, 'w'), $input->getOption("streamable"), $input->getOption("shebang"), $filter);
        return self::SUCCESS;
    }

    // TODO make this an enum once PHP 8.0 is dead
    const FILTER_NONE = 0;
    const FILTER_BASE64 = 1;
    const FILTER_GZIP = 2;
    const FILTER_BZIP = 3;

    /**
     * @param array<string,resource|string|null> $files
     * @param list<string> $bootstraps
     * @return void
     */
    public static function process(array $files, array $bootstraps, mixed $output, bool $streamable = false, string $shebang = "", int $filter = self::FILTER_NONE): void
    {
        $manifest = [];
        if($shebang) fwrite($output, "$shebang\n");
        // $data: compressed portion
        $data = fopen("php://temp", "rw");
        PnpStream::register();

        $compression = match($filter) {
            self::FILTER_NONE => fn(string $s) => $s,
            self::FILTER_BASE64 => Closure::fromCallable('base64_encode'),
            self::FILTER_GZIP => Closure::fromCallable('gzencode'),
            self::FILTER_BZIP => Closure::fromCallable('bzcompress'),
        };

        foreach($files as $filename => $conts) {
            $manifest[$filename] = [ftell($data)];
            if(is_resource($conts)) {
                $conts = stream_get_contents($conts);
            } elseif(is_null($conts)) {
                $conts = file_get_contents($filename);
            }
            if(str_ends_with($filename, ".php")) {
                $conts = self::minify($conts);
            }
            fwrite($data, $compression($conts));
            $manifest[$filename][1] = ftell($data) - $manifest[$filename][0];
        }
        $manifestposition = ftell($data);
        fwrite($data, $compression(json_encode($manifest)));
        $initposition = ftell($data);
        fwrite($data, $compression(str_replace('<?php','',self::minify(file_get_contents(__DIR__ . "/PnpStream.php") . "PnpStream::register();"))));

        fseek($data, 0);

        $latte = new Engine();
        $pageContents = self::minify($latte->renderToString(__DIR__ . "/../bootstraps/bootstrap.latte", [
            "manifestLength" => $initposition - $manifestposition,
            "filter" => match($filter){
                self::FILTER_NONE => "",
                self::FILTER_BASE64 => 'base64_decode',
                self::FILTER_GZIP => 'gzdecode',
                self::FILTER_BZIP => 'bzdecompress',
            },
            "manifestOffset" => $manifestposition,
            "bootstraps" => $bootstraps,
            "streamable" => $streamable,
        ]));
        if($streamable) {
            $dataStrpos = strpos($pageContents, "@@data@@");
            fwrite($output, substr($pageContents, 0, $dataStrpos));
            stream_filter_append($data, "convert.base64-encode", STREAM_FILTER_READ);
            stream_copy_to_stream($data, $output);
            fwrite($output, substr($pageContents, $dataStrpos + strlen("@@data@@")));
        } else {
            fwrite($output, $pageContents);
            stream_copy_to_stream($data, $output);
        }
    }

    public static function go(): void
    {
        (new class extends Application {
            public function __construct()
            {
                parent::__construct("PNP");
                $this->add(new Pnp());
                $this->setDefaultCommand("run", true);
            }
        })->run();
    }
}