<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Product picture. On the create page it only holds the chosen file (v-model); on the edit page
 * (productId given) it uploads and removes the picture straight away.
 */
const props = defineProps({ modelValue: { default: null }, productId: Number, current: String, error: String });
const emit = defineEmits(['update:modelValue']);
const preview = ref(null);
const busy = ref(false);
const shown = computed(() => preview.value || props.current || null);

const pick = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    preview.value = URL.createObjectURL(file);
    if (props.productId) {
        busy.value = true;
        router.post(route('inventory.products.image.upload', props.productId), { image: file }, { forceFormData: true, preserveScroll: true, onFinish: () => (busy.value = false) });
    } else {
        emit('update:modelValue', file);
    }
};
const remove = () => {
    preview.value = null;
    if (props.productId) router.delete(route('inventory.products.image.delete', props.productId), { preserveScroll: true });
    else emit('update:modelValue', null);
};
</script>

<template>
    <div class="flex items-center gap-3" data-product-image>
        <div class="w-20 h-20 rounded-xl border border-slate-200 bg-slate-50 overflow-hidden flex items-center justify-center text-slate-300 text-3xs">
            <img v-if="shown" :src="shown" alt="" class="w-full h-full object-cover" data-image-preview />
            <span v-else>بلا صورة</span>
        </div>
        <div class="space-y-1.5">
            <label class="inline-block px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 cursor-pointer hover:bg-slate-50">
                {{ shown ? 'تغيير الصورة' : 'رفع صورة' }}
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :disabled="busy" @change="pick" data-image-input />
            </label>
            <button v-if="shown" type="button" class="block text-3xs font-bold text-rose-600" @click="remove">حذف الصورة</button>
            <p v-if="error" class="text-3xs font-bold text-rose-600">{{ error }}</p>
            <p class="text-3xs text-slate-400">PNG أو JPG أو WebP حتى 2 ميجا</p>
        </div>
    </div>
</template>
