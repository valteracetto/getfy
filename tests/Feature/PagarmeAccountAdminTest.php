<?php

namespace Tests\Feature;

use App\Models\PagarmeAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cadastro da conta Pagar.me no admin: a chave financeira é validada na API, fica criptografada
 * e nunca volta para o navegador. Só admin acessa.
 */
class PagarmeAccountAdminTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'tenant_id' => 1]);
        PagarmeAccount::create([
            'label' => 'Conta principal', 'recipient_id' => 're_test_main', 'finance_secret_key' => 'sk_test_finance_secret',
        ])->attachTenants([10, 11]);
    }

    public function test_producer_cannot_open_the_admin_pages(): void
    {
        $producer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 10]);
        $account = PagarmeAccount::sole();

        $this->actingAs($producer)->get('/admin/financeiro/pagarme')->assertForbidden();
        $this->actingAs($producer)->get('/admin/financeiro/pagarme/'.$account->id)->assertForbidden();
        $this->actingAs($producer)->postJson('/admin/financeiro/pagarme/'.$account->id.'/sync')->assertForbidden();
    }

    public function test_admin_registers_account_with_detected_recipient_and_key_never_leaves_the_server(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/recipients?*' => Http::response(['data' => [['id' => 're_test_new', 'name' => 'Empresa']]]),
            'api.pagar.me/core/v5/recipients/re_test_new/balance' => Http::response(['available_amount' => 0, 'waiting_funds_amount' => 0]),
        ]);

        $this->actingAs($this->admin)->post('/admin/financeiro/pagarme', [
            'label' => 'Nova conta', 'finance_secret_key' => 'sk_test_new_account', 'tenant_ids' => [17, 18],
        ])->assertRedirect();

        $account = PagarmeAccount::where('recipient_id', 're_test_new')->sole();
        $this->assertSame([17, 18], $account->tenantIds());
        $this->assertSame('sk_test_new_account', $account->finance_secret_key);

        $content = $this->actingAs($this->admin)->get('/admin/financeiro/pagarme')->assertOk()->getContent();
        $this->assertStringNotContainsString('sk_test_new_account', $content);
        $this->assertStringNotContainsString('sk_test_finance_secret', $content);
    }

    public function test_registering_with_a_key_without_permission_fails(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Authorization has been denied for this request.'], 401)]);

        $this->actingAs($this->admin)->post('/admin/financeiro/pagarme', [
            'label' => 'Ruim', 'finance_secret_key' => 'sk_test_bad', 'tenant_ids' => [19],
        ])->assertSessionHasErrors('finance_secret_key');

        $this->assertNull(PagarmeAccount::where('label', 'Ruim')->first());
    }
}
