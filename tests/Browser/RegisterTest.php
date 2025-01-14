<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\Browser\Pages\Home;
use Tests\DuskTestCase;

class RegisterTest extends DuskTestCase
{
    /**
     * A basic browser test example.
     *
     * @throws \Exception
     * @throws \Throwable
     *
     * @return void
     */
    public function testVisit()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit(new Home())
                    ->visit('/analytics')
                    ->assertPathIs('/spid/login')
                    ->waitForText('Entra con SPID')
                    ->click('@spid_login_button')
                    ->waitForText('Non hai SPID?')
                    ->assertSee('Non hai SPID?');
        });
        $this->injectFakeSpidSession();
        $this->browse(function (Browser $browser) {
            $browser->visit(new Home())
                ->click('a[href="/analytics"]')
                ->assertPathIs('/register')
                ->assertSee('Registrazione')
                ->type('email', 'nome.cognome@webanalytics.italia.it')
                ->click('label[for="accept_terms"]')
                ->press(__('Registrati'))
                ->waitForText('In attesa di conferma')
                ->assertSee(__('Per andare avanti è necessario prima effetturare una verifica'));
        });
        $signedUrl = $this->getSignedUrl(1);
        $this->browse(function (Browser $browser) use ($signedUrl) {
            $browser->visit($signedUrl)
                    ->assertPathIs('/websites')
                    ->visit('/user/verify')
                    ->waitForText("L'indirizzo email")
                    ->assertSee('è già stato verificato');
        });
    }
}
