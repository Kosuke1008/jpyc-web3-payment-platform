<?php

namespace Tests\Feature;

use Tests\TestCase;

class UserPaymentsPageSecurityTest extends TestCase
{
    public function test_payment_history_renders_api_values_as_text_not_html(): void
    {
        $response = $this->get('/user/payments');

        $response
            ->assertOk()
            ->assertSee('paymentsDiv.replaceChildren(...cards);', false)
            ->assertSee('element.textContent = String(value);', false)
            ->assertDontSee('paymentsDiv.innerHTML', false);
    }
}
