<script setup>
import { onMounted, ref } from 'vue';
import QRCode from 'qrcode';
import { ComputerDesktopIcon, DevicePhoneMobileIcon } from '@heroicons/vue/24/outline';

/**
 * Local install: how the office's other computers and phones reach the
 * system (same network), with a QR code for phones.
 */
const props = defineProps({ urls: { type: Array, default: () => [] } });
const qr = ref('');

onMounted(async () => {
    if (props.urls.length) qr.value = await QRCode.toDataURL(props.urls[0], { margin: 1, width: 160 });
});
</script>

<template>
    <div class="bg-white rounded-2xl border border-emerald-200 p-5 shadow-xs flex flex-col sm:flex-row gap-5 items-start" data-local-access>
        <div class="flex-1 space-y-2">
            <h3 class="font-black text-slate-900 flex items-center gap-2"><ComputerDesktopIcon class="w-5 h-5 text-emerald-600" /> {{ $t('cmp.access_from_office_devices') }}</h3>
            <template v-if="urls.length">
                <p class="text-sm text-slate-600">{{ $t('cmp.from_any_device_or_phone_on_the') }}</p>
                <p v-for="u in urls" :key="u" class="font-mono text-lg font-black text-indigo-700" dir="ltr">{{ u }}</p>
                <p class="text-xs text-slate-500">{{ $t('cmp.this_device_must_stay_on_if_the') }}</p>
            </template>
            <p v-else class="text-sm text-slate-600">{{ $t('cmp.this_device_is_not_on_a_local_ne') }}</p>
        </div>
        <div v-if="qr" class="text-center">
            <img :src="qr" alt="QR" class="w-36 h-36 rounded-xl border border-slate-100" />
            <p class="text-[11px] text-slate-500 mt-1 flex items-center justify-center gap-1"><DevicePhoneMobileIcon class="w-4 h-4" /> {{ $t('cmp.scan_it_with_your_phone_camera') }}</p>
        </div>
    </div>
</template>
