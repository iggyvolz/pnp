<?php

namespace iggyvolz\Pnp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Pnp extends Command
{
    protected static $defaultName = "run";
    protected function configure(): void
    {
        $this->addArgument("output", mode: InputArgument::REQUIRED, description: "Output file");
        $this->addOption("bootstrap", "b", mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, description: "Script(s) to bootstrap (php://stdin for stdin)");
        $this->addOption("vendor", mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, description: "Path to /vendor directory(ies) for project");
        $this->addOption("shebang", mode: InputOption::VALUE_REQUIRED, description: "Shebang to add to the application", default: "/usr/bin/env php");
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
        $classmap = [];
        foreach($vendorDirs as $vendorDir) {
            $classmap = [...$classmap, ...(require $vendorDir . "/composer/autoload_classmap.php")];
            foreach(file_exists($vendorDir . "/composer/autoload_files.php") ? require $vendorDir . "/composer/autoload_files.php" : [] as $file) {
                $bootstraps[$file] = null;
            }
        }
        // Prepend vendor bootstraps before bootstrap script
        $bootstraps = [...$bootstraps, ...array_fill_keys($inputBootstraps, null)];
        try {
            $compression = match ($input->getOption("compression")) {
                "none" => Compression::NONE,
                "gzip" => Compression::GZIP,
                "bzip" => Compression::BZIP,
                "base64" => Compression::BASE64,
                default => throw new \RuntimeException("Invalid option for compression")
            };
            $compression->guard();
        } catch(\RuntimeException $e) {
            $output->writeln($e->getMessage());
            return self::FAILURE;
        }
        $output = $input->getArgument("output");
        if($output === "-") $output = "php://stdout";
        self::process($bootstraps, fopen($output, "w"), $input->getOption("shebang"), $input->getOption("streamable") ? true : false, $classmap, $compression);
        return self::SUCCESS;
    }

    /**
     * Strip initial #! and ?php tags from a string
     */
    private static function stripInitialTags(string $contents): string
    {
        if(str_starts_with($contents, "#!")) {
            $contents = substr($contents, strpos($contents, "\n"));
        }
        if(str_starts_with($contents, "<?php")) {
            return substr($contents, strlen("<?php"));
        } else {
            return "?>$contents";
        }
    }

    /**
     * @param array<string,string|null> $bootstraps File name => file contents (or null to read from name)
     * @param mixed $output Stream to output to
     * @param array $classmap
     * @param Compression $compression
     * @return void
     */
    public static function process(array $bootstraps, mixed $output, string $shebang = "/usr/bin/env php", bool $streamable = false, array $classmap = [], Compression $compression = Compression::NONE): void
    {
        $data = fopen("php://temp", "rw");
        /**
         * @var array<string,Offset> File => offset
         */
        $offsets = [];
        $bootstrapOffsets = [];
        if(!empty($classmap)) {
            // Add classmap files
            foreach($classmap as $filename) {
                if(!array_key_exists($filename, $offsets)) {
                    $offsets[$filename] = self::write($data, self::stripInitialTags(file_get_contents($filename)), $compression);
                }
            }
            // Add an autoloader
            $autoloader = "spl_autoload_register(function(string \$c){switch(\$c){";
            foreach($classmap as $class => $file) {
                $offset = $offsets[$file];
                $autoloader .= "case " . var_export($class, true) . ":e($offset->start,$offset->length,".var_export($file,true).");break;";
            }
            $autoloader .= "}});";
            $bootstrapOffsets["[autoloader]"]= self::write($data, $autoloader, $compression);
        }
        foreach($bootstraps as $filename => $contents) {
            $contents ??= file_get_contents($filename);
            if(is_null($contents))  throw new \RuntimeException("$filename does not exist");
            $bootstrapOffsets[$filename] = self::write($data, self::stripInitialTags($contents), $compression);
        }

        // Write the body - minify a few elements to save space
        $minify = fn(string $s): string => str_replace(["\n", "    ", ") {", " = "], ["", "", "){", "="], $s);
        self::write($output, "#!$shebang\n<?php\n");
        fseek($data, 0);
        $dataStream = $streamable ? var_export("data://text/plain;base64," . base64_encode(stream_get_contents($data)), true) : "__FILE__";
        $streamOffset = $streamable ? "0" : "__COMPILER_HALT_OFFSET__";
        self::write($output, $minify(<<<PHP
            {$compression->guardCode()}
            function e(\$a,\$b,\$n) {
                global \$s,\$f,\$e;
                fseek(\$s,\$f+\$a);
                \$e({$compression->decompressCode('fread($s,$b)')},\$n);
            }
            \$f = $streamOffset;
            \$s = fopen($dataStream, 'r');
            if(extension_loaded('ffi')) {
                \$h = FFI::cdef("int zend_eval_string(const char *str, int retval_ptr, const char *string_name);");
                \$e = function(\$s,\$n){global \$h;\$h->zend_eval_string(\$s,0,\$n);};
            } else {
                \$e = function(\$s){eval(\$s);};
            }
        PHP
        ));
        foreach($bootstrapOffsets as $name => $offset) {
            self::write($output, "e($offset->start,$offset->length,".var_export($name, true).");");
        }
        if(!$streamable) {
            self::write($output, "__halt_compiler();");
            stream_copy_to_stream($data, $output);
        }
        fclose($data);
        fclose($output);
    }

    /**
     * @param resource $stream
     */
    private static function write(mixed $stream, string $data, Compression $compression = Compression::NONE): Offset
    {
        $start = ftell($stream);
        fwrite($stream, $compression->compress($data));
        $end = ftell($stream);
        return new Offset($start, $end - $start);
    }
}