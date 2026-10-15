<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    tenants: { type: Array, default: () => [] },
});

const showForm = ref(props.accounts.length === 0);
const form = useForm({ label: '', finance_secret_key: '', recipient_id: '', tenant_ids: [] });

function submit() {
    form.post('/admin/financeiro/pagarme', {
        preserveScroll: true,
        onFinish: () => form.reset('finance_secret_key'),
    });
}

function tenantName(id) {
    const t = props.tenants.find((x) => x.tenant_id === id);
    return t ? `${t.name} (#${id})` : `#${id}`;
}

const inputClass = 'mt-1 block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white';
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Contas Pagar.me</h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
                    Chave financeira (separada da chave do checkout) para ler saldo, recebíveis e taxas reais.
                    Produtores (tenants) na mesma conta compartilham o saldo; os recebíveis são separados por produtor.
                </p>
            </div>
            <button v-if="!showForm" type="button" class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white" @click="showForm = true">
                Cadastrar conta
            </button>
        </div>

        <ul v-if="accounts.length" class="divide-y divide-zinc-100 overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900/80">
            <li v-for="a in accounts" :key="a.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div>
                    <p class="font-medium text-zinc-900 dark:text-white">{{ a.label }}</p>
                    <p class="text-xs text-zinc-500">
                        Recebedor {{ a.recipient_id }} · Produtores: {{ a.tenant_ids.map(tenantName).join(', ') || '—' }}
                    </p>
                    <p v-if="a.last_sync_error" class="text-xs text-amber-700 dark:text-amber-300">{{ a.last_sync_error }}</p>
                </div>
                <Link :href="`/admin/financeiro/pagarme/${a.id}`" class="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">
                    Abrir
                </Link>
            </li>
        </ul>

        <form v-if="showForm" class="max-w-2xl space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80" @submit.prevent="submit">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Nova conta</h2>
            <ol class="list-decimal space-y-1 pl-5 text-xs text-zinc-500">
                <li>No dashboard da Pagar.me: Configurações → Chaves → Criar chave, escopo <strong>Personalizado</strong>.</li>
                <li>Dê leitura em Recebedores, Saldo, Recebíveis, Liquidações, Transferências e Antecipações.</li>
                <li>Cole a chave abaixo. Ela fica criptografada e nunca é exibida de novo.</li>
            </ol>
            <label class="block text-xs font-medium text-zinc-500">
                Nome
                <input v-model="form.label" required maxlength="120" placeholder="Ex.: Conta principal" :class="inputClass" />
                <span v-if="form.errors.label" class="text-xs text-red-600">{{ form.errors.label }}</span>
            </label>
            <label class="block text-xs font-medium text-zinc-500">
                Chave financeira (sk_…)
                <input v-model="form.finance_secret_key" type="password" required autocomplete="off" :class="inputClass" />
                <span v-if="form.errors.finance_secret_key" class="text-xs text-red-600">{{ form.errors.finance_secret_key }}</span>
            </label>
            <label class="block text-xs font-medium text-zinc-500">
                ID do recebedor (opcional; detectado automaticamente se a conta tiver um só)
                <input v-model="form.recipient_id" placeholder="re_…" :class="inputClass" />
                <span v-if="form.errors.recipient_id" class="text-xs text-red-600">{{ form.errors.recipient_id }}</span>
            </label>
            <fieldset>
                <legend class="text-xs font-medium text-zinc-500">Produtores que vendem nesta conta</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label v-for="t in tenants" :key="t.tenant_id" class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                        <input v-model="form.tenant_ids" type="checkbox" :value="t.tenant_id" />
                        {{ t.name }} <span class="text-xs text-zinc-400">#{{ t.tenant_id }} · {{ t.email }}</span>
                    </label>
                </div>
                <span v-if="form.errors.tenant_ids" class="text-xs text-red-600">{{ form.errors.tenant_ids }}</span>
            </fieldset>
            <div class="flex gap-2">
                <button type="submit" class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="form.processing">
                    {{ form.processing ? 'Validando chave…' : 'Cadastrar' }}
                </button>
                <button v-if="accounts.length" type="button" class="rounded-lg px-4 py-2 text-sm text-zinc-500" @click="showForm = false">Cancelar</button>
            </div>
        </form>
    </div>
</template>
