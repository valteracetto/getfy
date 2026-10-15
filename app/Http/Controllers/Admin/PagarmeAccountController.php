<?php

namespace App\Http\Controllers\Admin;

use App\Gateways\Pagarme\PagarmeFinanceClient;
use App\Gateways\Pagarme\PagarmeFinanceException;
use App\Http\Controllers\Controller;
use App\Models\PagarmeAccount;
use App\Models\User;
use App\Services\PagarmeFinanceSummaryService;
use App\Services\PagarmeFinanceSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin: contas Pagar.me (chave financeira + recebedor + tenants). Rotas com role:admin.
 */
class PagarmeAccountController extends Controller
{
    public function __construct(
        private readonly PagarmeFinanceSummaryService $summary,
    ) {}

    public function index(): Response
    {
        $producers = User::query()
            ->whereIn('role', [User::ROLE_INFOPRODUTOR, User::ROLE_ADMIN])
            ->whereNotNull('tenant_id')
            ->orderBy('tenant_id')
            ->get(['id', 'name', 'email', 'tenant_id', 'role'])
            ->unique('tenant_id')
            ->map(fn (User $u) => ['tenant_id' => (int) $u->tenant_id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        return Inertia::render('Financeiro/PagarmeAccounts', [
            'accounts' => PagarmeAccount::query()->orderBy('id')->get()->map(fn (PagarmeAccount $a) => [
                'id' => $a->id,
                'label' => $a->label,
                'recipient_id' => $a->recipient_id,
                'tenant_ids' => $a->tenantIds(),
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'last_sync_error' => $a->last_sync_error,
            ]),
            'tenants' => $producers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'finance_secret_key' => ['required', 'string', 'max:255', 'starts_with:sk_'],
            'recipient_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => ['integer', 'min:1'],
        ]);

        $recipientId = $this->resolveRecipient(trim($data['finance_secret_key']), $data['recipient_id'] ?? null);
        if (PagarmeAccount::where('recipient_id', $recipientId)->exists()) {
            throw ValidationException::withMessages(['recipient_id' => 'Este recebedor já está cadastrado.']);
        }

        $account = PagarmeAccount::create([
            'label' => $data['label'],
            'recipient_id' => $recipientId,
            'finance_secret_key' => trim($data['finance_secret_key']),
        ]);
        $account->attachTenants($data['tenant_ids']);

        return redirect('/admin/financeiro/pagarme/'.$account->id)
            ->with('success', 'Conta Pagar.me cadastrada. A primeira sincronização roda em até 15 minutos (ou use "Sincronizar agora").');
    }

    public function update(Request $request, PagarmeAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'finance_secret_key' => ['nullable', 'string', 'max:255', 'starts_with:sk_'],
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => ['integer', 'min:1'],
        ]);

        $attrs = ['label' => $data['label']];
        if (! empty($data['finance_secret_key'])) {
            $this->resolveRecipient(trim($data['finance_secret_key']), $account->recipient_id);
            $attrs['finance_secret_key'] = trim($data['finance_secret_key']);
        }
        $account->update($attrs);
        $account->attachTenants($data['tenant_ids']);

        return back()->with('success', 'Conta atualizada.');
    }

    public function destroy(PagarmeAccount $account): RedirectResponse
    {
        $account->delete();

        return redirect('/admin/financeiro/pagarme')->with('success', 'Conta removida do Getfy (nada muda na Pagar.me).');
    }

    public function show(Request $request, PagarmeAccount $account): Response
    {
        return Inertia::render('Financeiro/PagarmeAccountAdmin', [
            'account' => $this->summary->account($account, true, (bool) $request->boolean('fresh')),
            'tenant_ids' => $account->tenantIds(),
        ]);
    }

    public function sync(PagarmeAccount $account, PagarmeFinanceSync $sync): JsonResponse
    {
        $stats = $sync->syncAccount($account);

        return response()->json(['stats' => $stats, 'error' => $account->fresh()->last_sync_error]);
    }

    /**
     * Valida a chave na API: descobre o recebedor (se não informado) e confere que o saldo pode ser lido.
     */
    private function resolveRecipient(string $secretKey, ?string $recipientId): string
    {
        try {
            if (! $recipientId) {
                $recipients = (new PagarmeFinanceClient($secretKey))->recipients();
                if (count($recipients) !== 1 || empty($recipients[0]['id'])) {
                    throw ValidationException::withMessages([
                        'recipient_id' => count($recipients) === 0
                            ? 'Nenhum recebedor encontrado com esta chave. Informe o ID do recebedor (re_…).'
                            : 'A conta tem mais de um recebedor. Informe o ID do recebedor principal (Configurações → Recebedores).',
                    ]);
                }
                $recipientId = (string) $recipients[0]['id'];
            }
            (new PagarmeFinanceClient($secretKey, $recipientId))->balance();
        } catch (PagarmeFinanceException $e) {
            throw ValidationException::withMessages([
                'finance_secret_key' => $e->getMessage().' Crie a chave com acesso a Recebedores, Saldo, Recebíveis, Liquidações, Transferências e Antecipações.',
            ]);
        }

        return $recipientId;
    }
}
