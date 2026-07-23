<?php

namespace App\Base\System\Services;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Projects database-backed runtime parameters into framework services that
 * Laravel configures before controllers and Livewire components run.
 */
final readonly class RuntimeConfigurationApplier
{
    public function __construct(
        private SystemRuntimeSettings $system,
        private MailRuntimeSettings $mail,
    ) {}

    public function apply(): void
    {
        try {
            if (! Schema::hasTable('base_settings')) {
                return;
            }

            config([
                'app.name' => $this->system->productName(),
                'session.lifetime' => $this->system->sessionLifetimeMinutes(),
            ]);

            foreach ($this->mail->configuration() as $key => $value) {
                config(["mail.{$key}" => $value]);
            }
        } catch (Throwable) {
            // Installation, recovery, and migration commands must remain
            // usable before the settings database is available.
        }
    }
}
