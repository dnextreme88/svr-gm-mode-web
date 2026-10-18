<?php

namespace App\Console\Commands;

use App\Support\EnumTypeScriptGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('gm:types {--check : Fail if the generated file is out of date}')]
#[Description('Generate resources/js/types/enums.ts from the PHP backed enums')]
class GenerateEnumTypes extends Command
{
    public function handle(EnumTypeScriptGenerator $generator): int
    {
        $path = base_path(EnumTypeScriptGenerator::OUTPUT_PATH);
        $contents = $generator->generate();

        if ($this->option('check')) {
            if (! File::exists($path) || File::get($path) !== $contents) {
                $this->error('enums.ts is out of date. Run `php artisan gm:types`.');

                return self::FAILURE;
            }

            $this->info('enums.ts is up to date.');

            return self::SUCCESS;
        }

        File::put($path, $contents);
        $this->info('Wrote '.EnumTypeScriptGenerator::OUTPUT_PATH.' ('.count($generator->enums()).' enums).');

        return self::SUCCESS;
    }
}
