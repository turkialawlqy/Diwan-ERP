<script setup>
import { notify } from '@/composables/useDialog';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import {
    XMarkIcon,
    PencilSquareIcon,
    TrashIcon,
    PlusIcon,
    PhotoIcon,
    DocumentIcon,
    ArrowUpTrayIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    customer: {
        type: Object,
        default: null,
    },
    nextCode: {
        type: String,
        default: '1-000001',
    },
});

const emit = defineEmits(['close', 'saved']);

// Form State
const form = ref({
    customer_type: 'commercial', // 'commercial' or 'individual'
    code: '',
    name: '',
    company_name: '',
    contact_name: '',
    email: '',
    phone: '',
    mobile: '',
    tax_number: '',
    commercial_registration: '',
    address: '',
    building_number: '',
    street_name: '',
    district: '',
    city: '',
    province: '',
    postal_code: '',
    additional_details: '',
    country: 'المملكة العربية السعودية',
    opening_balance: 0,
    credit_limit: 0,
    status: 'active',
    notes: '',
    additional_contacts: [],
    attachments: [],
});

// Section visibility toggles matching checkboxes in screenshot
const showPhoneSection = ref(true);
const showEmailSection = ref(true);
const showAddressSection = ref(true);

const isCodeEditable = ref(false);
const isSubmitting = ref(false);
const formErrors = ref({});
const isDraggingFile = ref(false);
const fileInput = ref(null);

const countries = [
    'المملكة العربية السعودية',
    'الإمارات العربية المتحدة',
    'الكويت',
    'قطر',
    'مملكة البحرين',
    'سلطنة عمان',
    'مصر',
    'الأردن',
];

// Initialize / Sync form data
watch(
    () => [props.show, props.customer],
    ([isOpen, cust]) => {
        if (!isOpen) return;

        if (cust) {
            // Editing existing customer
            form.value = {
                customer_type: cust.customer_type || (cust.tax_number || cust.commercial_registration ? 'commercial' : 'individual'),
                code: cust.code || ('1-' + String(cust.id).padStart(6, '0')),
                name: cust.name || '',
                company_name: cust.company_name || '',
                contact_name: cust.contact_name || '',
                email: cust.email || '',
                phone: cust.phone || '',
                mobile: cust.mobile || '',
                tax_number: cust.tax_number || '',
                commercial_registration: cust.commercial_registration || '',
                address: cust.address || '',
                building_number: cust.building_number || '',
                street_name: cust.street_name || '',
                district: cust.district || '',
                city: cust.city || 'الرياض',
                province: cust.province || '',
                postal_code: cust.postal_code || '',
                additional_details: cust.additional_details || '',
                country: cust.country || 'المملكة العربية السعودية',
                opening_balance: cust.opening_balance ?? 0,
                credit_limit: cust.credit_limit ?? 0,
                status: cust.status || 'active',
                notes: cust.notes || '',
                additional_contacts: Array.isArray(cust.additional_contacts) ? [...cust.additional_contacts] : [],
                attachments: Array.isArray(cust.attachments) ? [...cust.attachments] : [],
            };

            showPhoneSection.value = !!(cust.phone || cust.mobile);
            showEmailSection.value = !!cust.email;
            showAddressSection.value = !!(cust.address || cust.city || cust.building_number || cust.district);
        } else {
            // Creating new customer
            form.value = {
                customer_type: 'individual', // Screenshot 2 defaults or shows individual/commercial
                code: props.nextCode || '1-000001',
                name: '',
                company_name: '',
                contact_name: '',
                email: '',
                phone: '',
                mobile: '',
                tax_number: '',
                commercial_registration: '',
                address: '',
                building_number: '',
                street_name: '',
                district: '',
                city: '',
                province: '',
                postal_code: '',
                additional_details: '',
                country: 'المملكة العربية السعودية',
                opening_balance: 0,
                credit_limit: 0,
                status: 'active',
                notes: '',
                additional_contacts: [],
                attachments: [],
            };

            showPhoneSection.value = true;
            showEmailSection.value = true;
            showAddressSection.value = true;
        }

        isCodeEditable.value = false;
        formErrors.value = {};
    },
    { immediate: true }
);

// Toggle code editing
const toggleCodeEdit = () => {
    isCodeEditable.value = !isCodeEditable.value;
};

// Additional contacts handlers
const addContact = () => {
    form.value.additional_contacts.push({
        name: '',
        phone: '',
        email: '',
        role: '',
    });
};

const removeContact = (index) => {
    form.value.additional_contacts.splice(index, 1);
};

// File upload handlers
const triggerFilePicker = () => {
    if (fileInput.value) {
        fileInput.value.click();
    }
};

const handleFiles = async (event) => {
    const files = event.target.files || event.dataTransfer.files;
    if (!files || !files.length) return;

    for (let i = 0; i < files.length; i++) {
        await uploadFile(files[i]);
    }
    if (fileInput.value) fileInput.value.value = '';
};

const uploadFile = async (file) => {
    const data = new FormData();
    data.append('file', file);

    try {
        const res = await axios.post(route('sales.clients.upload-attachment'), data, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (res.data?.success && res.data?.file) {
            form.value.attachments.push(res.data.file);
        }
    } catch (err) {
        console.error('File upload failed', err);
        // Fallback: save client-side object for offline/local preview
        form.value.attachments.push({
            name: file.name,
            size: file.size,
            type: file.type,
            url: URL.createObjectURL(file),
        });
    }
};

const removeAttachment = (index) => {
    form.value.attachments.splice(index, 1);
};

const onDropFile = (e) => {
    isDraggingFile.value = false;
    handleFiles(e);
};

// Save customer
const saveCustomer = async () => {
    if (!form.value.name.trim()) {
        formErrors.value = { name: ['يرجى إدخال اسم العميل'] };
        return;
    }

    isSubmitting.value = true;
    formErrors.value = {};

    try {
        let response;
        if (props.customer?.id) {
            response = await axios.put(route('sales.clients.update', props.customer.id), form.value);
        } else {
            response = await axios.post(route('sales.clients.store'), form.value);
        }

        emit('saved', response.data?.customer || form.value);
        emit('close');
    } catch (error) {
        if (error.response?.data?.errors) {
            formErrors.value = error.response.data.errors;
        } else {
            notify('حدث خطأ أثناء حفظ بيانات العميل، يرجى المحاولة مجدداً.');
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4 animate-in fade-in duration-200"
    >
        <div
            class="bg-white rounded-2xl w-full max-w-5xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[92vh]"
            dir="rtl"
        >
            <!-- Header Bar -->
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-white sticky top-0 z-10">
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-slate-800">
                        {{ customer ? 'تعديل بيانات العميل' : 'إضافة عميل' }}
                    </h2>
                </div>
                <button
                    type="button"
                    @click="emit('close')"
                    class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-lg transition-colors"
                >
                    <XMarkIcon class="w-5 h-5" />
                </button>
            </div>

            <!-- Scrollable Body -->
            <div class="overflow-y-auto p-6 sm:p-8 space-y-8 flex-1 bg-white">
                <!-- Two Columns Layout (Right: Main Information | Left: Contact Details) -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                    
                    <!-- RIGHT COLUMN: Customer Basic Info & Financials -->
                    <div class="space-y-5">
                        <!-- Customer Type Radio Options -->
                        <div class="flex items-center justify-between pb-2">
                            <span class="text-xs font-bold text-slate-700">النوع</span>
                            <div class="flex items-center gap-6">
                                <!-- Individual (فردي) -->
                                <label class="inline-flex items-center gap-2 cursor-pointer group">
                                    <input
                                        type="radio"
                                        v-model="form.customer_type"
                                        value="individual"
                                        class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-slate-300"
                                    />
                                    <span class="text-sm font-medium text-slate-700 group-hover:text-indigo-600 transition-colors">
                                        فردي
                                    </span>
                                </label>

                                <!-- Commercial (تجاري) -->
                                <label class="inline-flex items-center gap-2 cursor-pointer group">
                                    <input
                                        type="radio"
                                        v-model="form.customer_type"
                                        value="commercial"
                                        class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-slate-300"
                                    />
                                    <span class="text-sm font-medium text-slate-700 group-hover:text-indigo-600 transition-colors">
                                        تجاري
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Code Field with Edit Pencil Icon -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-bold text-slate-700">الكود</label>
                            </div>
                            <div class="relative flex items-center">
                                <button
                                    type="button"
                                    @click="toggleCodeEdit"
                                    class="absolute start-3 p-1 text-slate-400 hover:text-indigo-600 transition-colors"
                                    title="تعديل الكود يدويًا"
                                >
                                    <PencilSquareIcon class="w-4 h-4" />
                                </button>
                                <input
                                    v-model="form.code"
                                    :readonly="!isCodeEditable"
                                    type="text"
                                    class="w-full text-sm rounded-lg border border-slate-200 ps-10 pe-3 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    :class="[!isCodeEditable ? 'bg-slate-50/70 text-slate-700 cursor-default' : 'bg-white text-slate-900 border-indigo-300']"
                                    placeholder="1-000001"
                                />
                            </div>
                        </div>

                        <!-- Name Field -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">
                                الاسم <span class="text-rose-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                placeholder="مثال: شركة مدن الراقية المحدودة"
                                class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                :class="{ 'border-rose-400': formErrors.name }"
                            />
                            <p v-if="formErrors.name" class="mt-1 text-2xs text-rose-500 font-semibold">
                                {{ formErrors.name[0] }}
                            </p>
                        </div>

                        <!-- Commercial Fields (Tax & CR) - Shown ONLY when Type is 'commercial' -->
                        <div v-if="form.customer_type === 'commercial'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Commercial Registration (CR) -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">السجل التجاري</label>
                                <input
                                    v-model="form.commercial_registration"
                                    type="text"
                                    placeholder="مثال: 4030502848"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Tax / VAT Number -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الضريبي</label>
                                <input
                                    v-model="form.tax_number"
                                    type="text"
                                    maxlength="15"
                                    placeholder="مثال: 311576763100003"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>
                        </div>

                        <!-- Opening Balance -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الرصيد الافتتاحي</label>
                            <div class="relative">
                                <input
                                    v-model="form.opening_balance"
                                    type="number"
                                    step="0.01"
                                    placeholder="0.00"
                                    class="w-full text-sm rounded-lg border border-slate-200 pe-3 ps-10 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                                <span class="absolute start-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">
                                    ﷼
                                </span>
                            </div>
                        </div>

                        <!-- Notes Textarea -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                placeholder="أية ملاحظات إضافية عن العميل أو شروط السداد..."
                                class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y"
                            ></textarea>
                        </div>

                        <!-- Attachments Drag & Drop Area -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">المرفقات</label>
                            <div
                                @click="triggerFilePicker"
                                @dragover.prevent="isDraggingFile = true"
                                @dragleave.prevent="isDraggingFile = false"
                                @drop.prevent="onDropFile"
                                class="border-2 border-dashed rounded-xl p-6 text-center cursor-pointer transition-colors"
                                :class="[
                                    isDraggingFile
                                        ? 'border-indigo-500 bg-indigo-50/30'
                                        : 'border-slate-200 hover:border-slate-300 bg-slate-50/50'
                                ]"
                            >
                                <input
                                    ref="fileInput"
                                    type="file"
                                    multiple
                                    class="hidden"
                                    @change="handleFiles"
                                />
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <div class="w-12 h-12 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-400 shadow-2xs">
                                        <PhotoIcon class="w-6 h-6" />
                                    </div>
                                    <div class="text-xs text-slate-600">
                                        <span class="text-indigo-600 font-bold hover:underline">اضغط لرفع الملفات</span>
                                        <span> أو اسحبها هنا</span>
                                    </div>
                                    <p class="text-2xs text-slate-400">PDF, PNG, JPG (الحد الأقصى 10 ميجابايت)</p>
                                </div>
                            </div>

                            <!-- Uploaded Files List -->
                            <div v-if="form.attachments && form.attachments.length > 0" class="mt-3 space-y-2">
                                <div
                                    v-for="(file, idx) in form.attachments"
                                    :key="idx"
                                    class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-200 text-xs"
                                >
                                    <div class="flex items-center gap-2 overflow-hidden">
                                        <DocumentIcon class="w-4 h-4 text-indigo-500 shrink-0" />
                                        <span class="truncate font-medium text-slate-700">{{ file.name }}</span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="removeAttachment(idx)"
                                        class="text-rose-500 hover:text-rose-700 p-1"
                                        title="حذف الملف"
                                    >
                                        <TrashIcon class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- LEFT COLUMN: Contact Details & Address -->
                    <div class="space-y-5">
                        <div class="space-y-4">
                            <h3 class="text-sm font-bold text-slate-800">بيانات الاتصال</h3>

                            <!-- Contact Name (Shown for commercial clients as seen in screenshots) -->
                            <div v-if="form.customer_type === 'commercial'">
                                <label class="block text-xs font-bold text-slate-700 mb-1">اسم جهة الاتصال</label>
                                <input
                                    v-model="form.contact_name"
                                    type="text"
                                    placeholder="اسم الشخص المسؤول أو المفوض للتواصل..."
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Section Visibility Checkboxes -->
                            <div class="flex items-center gap-6 py-1 border-b border-slate-100">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="showPhoneSection"
                                        class="rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                                    />
                                    <span class="text-xs font-semibold text-slate-700">الهاتف</span>
                                </label>

                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="showEmailSection"
                                        class="rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                                    />
                                    <span class="text-xs font-semibold text-slate-700">بريد إلكتروني</span>
                                </label>

                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="showAddressSection"
                                        class="rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                                    />
                                    <span class="text-xs font-semibold text-slate-700">عنوان</span>
                                </label>
                            </div>

                            <!-- Phone & Mobile Row -->
                            <div v-if="showPhoneSection" class="grid grid-cols-1 sm:grid-cols-2 gap-4 animate-in fade-in duration-150">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الهاتف</label>
                                    <input
                                        v-model="form.phone"
                                        type="text"
                                        placeholder="011XXXXXXXX"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الجوال</label>
                                    <input
                                        v-model="form.mobile"
                                        type="text"
                                        placeholder="05XXXXXXXX"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>
                            </div>

                            <!-- Email Field -->
                            <div v-if="showEmailSection" class="animate-in fade-in duration-150">
                                <label class="block text-xs font-bold text-slate-700 mb-1">بريد إلكتروني</label>
                                <input
                                    v-model="form.email"
                                    type="email"
                                    placeholder="client@company.com"
                                    class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                />
                            </div>

                            <!-- Address Sub-fields (National Address Format) -->
                            <div v-if="showAddressSection" class="space-y-4 animate-in fade-in duration-150">
                                <p v-if="form.tax_number" class="text-3xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">العميل مسجل ضريبياً: رقم المبنى والشارع والحي والمدينة والرمز البريدي مطلوبة لإصدار الفاتورة الضريبية (كما في العنوان الوطني - سبل).</p>
                                <!-- National address: building number, street, additional number -->
                                <div class="grid grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">رقم المبنى</label>
                                        <input
                                            v-model="form.building_number"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="4"
                                            placeholder="4002"
                                            data-field="building_number"
                                            class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <p v-if="formErrors.building_number" class="text-3xs text-rose-600 mt-1">{{ formErrors.building_number[0] }}</p>
                                    </div>
                                    <div class="col-span-2">
                                        <label class="block text-xs font-bold text-slate-700 mb-1">اسم الشارع</label>
                                        <input
                                            v-model="form.street_name"
                                            type="text"
                                            placeholder="مثال: طريق الملك فهد"
                                            class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الإضافي</label>
                                    <input
                                        v-model="form.additional_details"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="4"
                                        placeholder="7007"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                    <p v-if="formErrors.additional_details" class="text-3xs text-rose-600 mt-1">{{ formErrors.additional_details[0] }}</p>
                                </div>

                                <!-- District & City -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">حي</label>
                                        <input
                                            v-model="form.district"
                                            type="text"
                                            placeholder="مثال: جامعة الملك عبدالعزيز"
                                            class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">مدينة</label>
                                        <input
                                            v-model="form.city"
                                            type="text"
                                            placeholder="مثال: جدة"
                                            class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>

                                <!-- Postal Code & Province / Governorate -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">رمز بريدي</label>
                                        <input
                                            v-model="form.postal_code"
                                            type="text"
                                            placeholder="مثال: 22254"
                                            class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 font-mono transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                        <p v-if="formErrors.postal_code" class="text-3xs text-rose-600 mt-1">{{ formErrors.postal_code[0] }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1">محافظة</label>
                                        <input
                                            v-model="form.province"
                                            type="text"
                                            placeholder="مثال: جدة"
                                            class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>

                                <!-- Country Dropdown Selector -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">دولة</label>
                                    <select
                                        v-model="form.country"
                                        class="w-full text-sm rounded-lg border border-slate-200 px-3 py-2 bg-white transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option value="">اختر</option>
                                        <option v-for="c in countries" :key="c" :value="c">{{ c }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BOTTOM SECTION: Additional Contacts -->
                <div class="pt-6 border-t border-slate-100 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-800">جهات اتصال إضافية</h3>
                        <button
                            type="button"
                            @click="addContact"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs transition-colors"
                        >
                            <PlusIcon class="w-4 h-4 stroke-2" />
                            <span>إضافة جهة اتصال جديدة</span>
                        </button>
                    </div>

                    <!-- Dynamic Additional Contacts List -->
                    <div v-if="form.additional_contacts.length > 0" class="space-y-3">
                        <div
                            v-for="(ct, idx) in form.additional_contacts"
                            :key="idx"
                            class="p-4 rounded-xl border border-slate-200 bg-slate-50/50 grid grid-cols-1 sm:grid-cols-4 gap-3 items-center"
                        >
                            <div>
                                <label class="block text-2xs font-semibold text-slate-500 mb-1">الاسم</label>
                                <input
                                    v-model="ct.name"
                                    type="text"
                                    placeholder="اسم المسؤول"
                                    class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 bg-white"
                                />
                            </div>
                            <div>
                                <label class="block text-2xs font-semibold text-slate-500 mb-1">الهاتف / الجوال</label>
                                <input
                                    v-model="ct.phone"
                                    type="text"
                                    placeholder="05XXXXXXXX"
                                    class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 bg-white"
                                />
                            </div>
                            <div>
                                <label class="block text-2xs font-semibold text-slate-500 mb-1">البريد الإلكتروني</label>
                                <input
                                    v-model="ct.email"
                                    type="email"
                                    placeholder="contact@email.com"
                                    class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 bg-white"
                                />
                            </div>
                            <div class="flex items-end gap-2">
                                <div class="flex-1">
                                    <label class="block text-2xs font-semibold text-slate-500 mb-1">الدور / المنصب</label>
                                    <input
                                        v-model="ct.role"
                                        type="text"
                                        placeholder="مدير المشتريات / محاسب"
                                        class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 bg-white"
                                    />
                                </div>
                                <button
                                    type="button"
                                    @click="removeContact(idx)"
                                    class="p-2 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors"
                                    title="حذف"
                                >
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Server validation errors for fields that have no inline message -->
            <div v-if="Object.keys(formErrors).some((k) => k !== 'name')" class="mx-6 mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 space-y-1">
                <p v-for="(msgs, key) in formErrors" v-show="key !== 'name'" :key="key" class="text-xs text-rose-700 font-semibold">{{ msgs[0] }}</p>
            </div>

            <!-- Footer Action Buttons (Matching Screenshot) -->
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-start gap-3">
                <button
                    type="button"
                    @click="saveCustomer"
                    :disabled="isSubmitting"
                    class="px-7 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold shadow-sm transition-colors disabled:opacity-50"
                >
                    {{ isSubmitting ? 'جاري الحفظ...' : 'حفظ' }}
                </button>
                <button
                    type="button"
                    @click="emit('close')"
                    class="px-6 py-2.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-sm font-medium transition-colors"
                >
                    إلغاء
                </button>
            </div>
        </div>
    </div>
</template>
