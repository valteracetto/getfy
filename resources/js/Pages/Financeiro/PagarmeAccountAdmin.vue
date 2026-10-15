<script setup>
import { ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PagarmeAccountPanel from '@/components/financeiro/pagarme/PagarmeAccountPanel.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    account: { type: Object, required: true },
    tenant_ids: { type: Array, default: () => [] },
});

const page = usePage();
const editing = ref(false);
const form = useForm({ label: props.account.label, finance_secret_key: '', tenant_ids: [...props.tenant_ids] });
const tenantsText = ref(props.tenant_ids.join(', '));

function save() {
    form.tenant_ids = tenantsText.value.split(/[,\s]+/).map(Number).filter((n) => Number.isInteger(n) && n > 0);
    form.put(`/admin/financeiro/pagarme/${props.account.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
            form.reset('finance_secret_key');
        },
    });
}

function remove() {
    if (window.confirm('Remover esta conta do Getfy? Nada muda na Pagar.me; o espelho local de recebíveis é apagado.')) {
        router.delete(`/admin/financeiro/pagarme/${props.account.id}`);
    }
}

const inputClass = 'mt-1 block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white';
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link href="/admin/financeiro/pagarme" class="text-xs text-zinc-500 hover:underline">← Contas Pagar.me</Link>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ account.label }}</h1>
                <p class="text-sm text-zinc-500">Tenants: {{ tenant_ids.map((id) => `#${id}`).join(', ') }}</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 dark:border-zinc-700 dark:text-zinc-200" @click="editing = !editing">
                    Editar
                </button>
                <button type="button" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 dark:border-red-900" @click="remove">
                    Remover
                </button>
            </div>
        </div>

        <p v-if="page.props.flash?.success" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ page.props.flash.success }}
        </p>

        <form v-if="editing" class="max-w-xl space-y-3 rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80" @submit.prevent="save">
            <label class="block text-xs font-medium text-zinc-500">
                Nome
                <input v-model="form.label" required :class="inputClass" />
            </label>
            <label class="block text-xs font-medium text-zinc-500">
                Trocar chave financeira (deixe vazio para manter)
                <input v-model="form.finance_secret_key" type="password" autocomplete="off" :class="inputClass" />
                <span v-if="form.errors.finance_secret_key" class="text-xs text-red-600">{{ form.errors.finance_secret_key }}</span>
            </label>
            <label class="block text-xs font-medium text-zinc-500">
                IDs dos tenants (produtores), separados por vírgula
                <input v-model="tenantsText" :class="inputClass" />
                <span v-if="form.errors.tenant_ids" class="text-xs text-red-600">{{ form.errors.tenant_ids }}</span>
            </label>
            <button type="submit" class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="form.processing">Salvar</button>
        </form>

        <PagarmeAccountPanel
            :account="account"
            :actions-base-url="`/admin/financeiro/pagarme/${account.id}`"
            :shared-tenants-count="tenant_ids.length"
        />
    </div>
</template>
