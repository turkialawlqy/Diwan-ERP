<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Documents\DocumentSharing;
use App\Services\Messaging\CompanyMessaging;
use App\Services\Sales\InvoiceService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** A company's own SMTP server and SMS / WhatsApp gateways. */
class CompanyMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::factory()->create();
        app(AccountingSetupService::class)->provision($this->company);
        app(TenantManager::class)->setTenant($this->company);
        $this->actingAs($this->userWithRole($this->company));
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_settings_are_saved_with_secrets_encrypted_and_never_sent_back(): void
    {
        $this->put(route('settings.messaging.update'), ['mail_host' => 'smtp.example.com', 'mail_port' => 587, 'mail_password' => 'S3cret!', 'mail_from_address' => 'billing@example.com',
            'sms_url' => 'https://api.sms.example/messages', 'sms_token' => 'tok-123'])->assertSessionHasNoErrors();

        $stored = $this->company->settings()->where('key', 'mail_password')->value('value');
        $this->assertNotSame('S3cret!', $stored);                                   // encrypted at rest
        $messaging = app(CompanyMessaging::class);
        $this->assertSame('S3cret!', $messaging->get($this->company->fresh(), 'mail_password'));

        $this->get(route('settings.messaging'))->assertOk()->assertInertia(fn ($p) => $p->where('values.mail_password', '')->where('values.mail_password_set', true)->where('values.mail_host', 'smtp.example.com'));

        // Left empty on a later save, the stored secret stays.
        $this->put(route('settings.messaging.update'), ['mail_host' => 'smtp2.example.com', 'mail_password' => ''])->assertSessionHasNoErrors();
        $this->assertSame('S3cret!', $messaging->get($this->company->fresh(), 'mail_password'));

        // A non-HTTPS gateway or a broken template is refused.
        $this->put(route('settings.messaging.update'), ['sms_url' => 'http://insecure.example/x'])->assertSessionHasErrors('sms_url');
        $this->put(route('settings.messaging.update'), ['sms_body' => '{not json'])->assertSessionHasErrors('sms_body');
    }

    public function test_the_company_smtp_becomes_a_mailer_and_the_gateway_receives_the_message(): void
    {
        $messaging = app(CompanyMessaging::class);
        $messaging->save($this->company, ['mail_host' => 'smtp.example.com', 'mail_port' => 465, 'mail_encryption' => 'ssl', 'mail_from_address' => 'billing@example.com', 'mail_from_name' => 'الفواتير']);
        $this->assertSame('company_'.$this->company->id, $messaging->mailer($this->company->fresh()));
        $this->assertSame([465, 'smtp.example.com'], [config('mail.mailers.company_'.$this->company->id.'.port'), config('mail.mailers.company_'.$this->company->id.'.host')]);
        $this->assertSame('billing@example.com', config('mail.from.address'));

        Http::fake(['api.sms.example/*' => Http::response(['id' => 1], 200)]);
        $messaging->save($this->company, ['sms_url' => 'https://api.sms.example/messages', 'sms_token' => 'tok-123', 'sms_sender' => 'ديوان', 'sms_body' => '{"to":"{to}","text":"{message}","from":"{sender}"}']);
        $result = $messaging->send($this->company->fresh(), 'sms', '966500000000', 'مرحبا "بك"');
        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok-123') && $r['to'] === '966500000000' && $r['text'] === 'مرحبا "بك"' && $r['from'] === 'ديوان');

        // A gateway pointing into the server's own network is never called.
        $messaging->save($this->company, ['whatsapp_url' => 'https://127.0.0.1/send', 'whatsapp_token' => 'x']);
        $this->assertFalse($messaging->send($this->company->fresh(), 'whatsapp', '966500000000', 'x')['ok']);
        Http::assertSentCount(1);
    }

    public function test_a_whatsapp_share_goes_through_the_gateway_when_configured_and_the_test_mail_uses_the_company_server(): void
    {
        $messaging = app(CompanyMessaging::class);
        $customer = Customer::create(['name' => 'عميل', 'customer_type' => 'commercial', 'status' => 'active', 'phone' => '0500000000']);
        $invoice = app(InvoiceService::class)->create(['customer_id' => $customer->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString()],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 15]], ['issue_stock' => false]);

        // No gateway: the wa.me link.
        $plain = app(DocumentSharing::class)->whatsapp($invoice, '0500000000', null, auth()->id());
        $this->assertStringContainsString('wa.me', $plain['url']);

        Http::fake(['gw.example/*' => Http::response('ok', 200)]);
        $messaging->save($this->company, ['whatsapp_url' => 'https://gw.example/wa', 'whatsapp_token' => 'tk']);
        $sent = app(DocumentSharing::class)->whatsapp($invoice->fresh(), '0500000000', 'فاتورتكم', auth()->id());
        $this->assertNull($sent['url']);
        $this->assertSame(['sent', true], [$sent['dispatch']->status, $sent['sent_by_gateway']]);
        Http::assertSent(fn ($r) => str_contains($r['message'], 'فاتورتكم') && $r['to'] === '966500000000');

        Mail::fake();
        $this->post(route('settings.messaging.test'), ['channel' => 'email', 'to' => 'me@example.com'])->assertSessionHasNoErrors();
    }
}
