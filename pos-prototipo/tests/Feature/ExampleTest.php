<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** La raíz lleva a la pantalla POS del negocio de demostración. */
    public function test_la_raiz_redirige_al_pos(): void
    {
        $this->get('/')->assertRedirect('/pos/negocio-1');
    }
}
