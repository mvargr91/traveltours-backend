<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSending;
use Laravel\Passport\Passport;
use Symfony\Component\Mime\Address;
use App\Models\Parametrizacion\Apariencia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::enablePasswordGrant();
        // Passport::hashClientSecrets();

        // Los correos salen a nombre de la marca (Parametrización > Apariencia), no del MAIL_FROM_NAME de .env.
        // Solo se cambia el remitente por defecto: el que un correo defina a propósito se respeta.
        Event::listen(MessageSending::class, function (MessageSending $evento) {
            $porDefecto = (string) config('mail.from.address');
            foreach ($evento->message->getFrom() as $remitente) {
                if (strcasecmp($remitente->getAddress(), $porDefecto) === 0 && $remitente->getName() === (string) config('mail.from.name')) {
                    $evento->message->from(new Address($remitente->getAddress(), Apariencia::nombreMarca()));
                    return;
                }
            }
        });
    }
}
