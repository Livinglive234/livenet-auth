<?php

namespace LiveNet\LiveNetAuth;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * The work happens in Providers/LiveNetAuthServiceProvider.php, which Pelican
 * loads automatically. This class only identifies the plugin.
 */
class LiveNetAuthPlugin implements Plugin
{
    public function getId(): string
    {
        return 'livenet-auth';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
