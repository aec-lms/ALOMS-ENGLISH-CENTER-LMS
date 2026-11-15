<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\StudentManagement\Models\Student;
use Tests\TestCase;

class FinanceAndPaymentGatewayTest extends TestCase
{
    public function test_card_gateway_rejects_raw_pan_and_cvv_and_settles_via_signed_webhook(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'branch-management',
            'student-management',
            'fees-management',
            'payment-management',
            'gateway-card',
            'gateway-sslcommerz',
        ]);

        $plugins = app(PluginManager::class);
        $plugins->saveSettings('gateway-card', [
            'merchant_id' => 'MRC-100',
            'api_secret' => 'sec_live_test',
            'webhook_secret' => 'whsec_test_123456',
        ], ['merchant_id', 'api_secret', 'webhook_secret']);

        $branchId = DB::table('branches')->insertGetId(['code' => 'DHK', 'name' => 'Dhaka', 'created_at' => now(), 'updated_at' => now()]);
        $studentUser = $this->createUserWithRole('student', $branchId);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'branch_id' => $branchId,
            'student_code' => 'STU-101',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $invoice = FeeInvoice::query()->create([
            'invoice_number' => 'INV-2026-001',
            'student_id' => $student->id,
            'branch_id' => $branchId,
            'subtotal_amount' => 3000,
            'discount_amount' => 500,
            'net_amount' => 2500,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'unpaid',
        ]);

        $admin = $this->createUserWithRole('admin');

        // Raw PAN/CVV must be rejected with 422 on both gateways
        $this->actingAs($admin)->postJson('/payments/card/checkout', [
            'invoice_id' => $invoice->id,
            'card_number' => '4111111111111111',
            'cvv' => '123',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/payments/sslcommerz/initiate', [
            'invoice_id' => $invoice->id,
            'pan' => '4111111111111111',
        ])->assertStatus(422);

        // Tokenized card checkout succeeds and stores zero PAN/CVV
        $checkout = $this->actingAs($admin)->postJson('/payments/card/checkout', [
            'invoice_id' => $invoice->id,
            'payment_token' => 'tok_visa_1234567890',
            'card_brand' => 'visa',
            'last4' => '4242',
        ])->assertOk()->json();

        $payload = json_encode([
            'transaction_ref' => $checkout['transaction_ref'],
            'processor_reference' => 'PROC-998877',
        ], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $payload, 'whsec_test_123456');

        $this->call(
            'POST',
            '/payments/card/webhook',
            [],
            [],
            [],
            ['HTTP_X_CARD_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertOk();

        $tx = PaymentTransaction::query()->where('transaction_ref', $checkout['transaction_ref'])->firstOrFail();
        $this->assertSame('settled', $tx->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertArrayNotHasKey('card_number', $tx->metadata ?? []);
        $this->assertArrayNotHasKey('cvv', $tx->metadata ?? []);
    }
}
