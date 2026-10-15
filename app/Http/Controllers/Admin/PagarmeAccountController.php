<?php

namespace App\Http\Controllers\Admin;

use App\Gateways\Pagarme\PagarmeFinanceClient;
use App\Gateways\Pagarme\PagarmeFinanceException;
use App\Http\Controllers\Controller;
use App\Models\PagarmeAccount;
use App\Models\PagarmeAnticipation;
use App\Models\User;
use App\Services\PagarmeAccountActionsService;
use App\Services\PagarmeFinanceSummaryService;
use App\Services\PagarmeFinanceSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin: contas Pagar.me (chave financeira + recebedor + tenants) e ações de dinheiro da conta.
 * Rotas com role:admin; ações de escrita também exigem GETFY_PAGARME_FINANCE_WRITE e a senha do admin.
 */
class PagarmeAccountController extends Controller
{
    public function __construct(
        private readonly PagarmeAccountActionsService $actions,
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
            'write_enabled' => (bool) config('getfy.pagarme_finance_write'),
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
            'write_enabled' => (bool) config('getfy.pagarme_finance_write'),
        ]);
    }

    public function sync(PagarmeAccount $account, PagarmeFinanceSync $sync): JsonResponse
    {
        $stats = $sync->syncAccount($account);

        return response()->json(['stats' => $stats, 'error' => $account->fresh()->last_sync_error]);
    }

    public function transfer(Request $request, PagarmeAccount $account): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:100', 'max:2147483647'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'current_password' => ['required', 'current_password'],
        ]);

        $result = $this->actions->transfer($account, $request->user(), (int) $data['amount_cents'], $data['idempotency_key'], $request->ip());

        return response()->json([
            'replayed' => $result['replayed'],
            'transfer' => [
                'id' => $result['transfer']->id,
                'status' => $result['transfer']->status,
                'amount_cents' => $result['transfer']->amount_cents,
                'fee_cents' => $result['transfer']->fee_cents,
            ],
        ]);
    }

    public function anticipationLimits(Request $request, PagarmeAccount $account): JsonResponse
    {
        $data = $request->validate([
            'timeframe' => ['required', Rule::in(['start', 'end'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->actions->limits($account, $data['timeframe'], $data['payment_date']));
    }

    public function simulateAnticipation(Request $request, PagarmeAccount $account): JsonResponse
    {
        $data = $request->validate([
            'timeframe' => ['required', Rule::in(['start', 'end'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'requested_amount_cents' => ['required', 'integer', 'min:100'],
        ]);

        return response()->json($this->actions->simulate($account, $data['timeframe'], $data['payment_date'], (int) $data['requested_amount_cents']));
    }

    public function anticipate(Request $request, PagarmeAccount $account): JsonResponse
    {
        $data = $request->validate([
            'timeframe' => ['required', Rule::in(['start', 'end'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'requested_amount_cents' => ['required', 'integer', 'min:100'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'current_password' => ['required', 'current_password'],
        ]);

        $result = $this->actions->anticipate(
            $account, $request->user(), $data['timeframe'], $data['payment_date'],
            (int) $data['requested_amount_cents'], $data['idempotency_key'], $request->ip(),
        );

        return response()->json([
            'replayed' => $result['replayed'],
            'anticipation' => ['id' => $result['anticipation']->id, 'status' => $result['anticipation']->status],
        ]);
    }

    public function cancelAnticipation(Request $request, PagarmeAccount $account, PagarmeAnticipation $anticipation): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $updated = $this->actions->cancelAnticipation($account, $request->user(), $anticipation, $request->ip());

        return response()->json(['anticipation' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    public function updateTransferSettings(Request $request, PagarmeAccount $account): JsonResponse
    {
        $data = $request->validate([
            'transfer_enabled' => ['required', 'boolean'],
            'transfer_interval' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'transfer_day' => ['required', 'integer', 'min:0', 'max:31'],
            'current_password' => ['required', 'current_password'],
        ]);

        $this->actions->updateTransferSettings(
            $account, $request->user(), (bool) $data['transfer_enabled'], $data['transfer_interval'], (int) $data['transfer_day'], $request->ip(),
        );

        return response()->json(['success' => true]);
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
