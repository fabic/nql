<?php

namespace Fabic\Nql\Laravel\Commands;

use Illuminate\Console\Command;

class DummyNqlCommand extends Command
{
    protected $signature = 'nql:dummy 
                {query : Some NQL query} 
                {void? : some optional argument}';

    protected $description = 'Dummy NQL command that does nothing at all.';

    public function handle()
    {
//        $permissionClass = app(PermissionContract::class);
//
//        $permission = $permissionClass::create([
//            'name' => $this->argument('name'),
//            'guard_name' => $this->argument('guard'),
//        ]);

        $this->info("NQL: hello world !");
    }
}
