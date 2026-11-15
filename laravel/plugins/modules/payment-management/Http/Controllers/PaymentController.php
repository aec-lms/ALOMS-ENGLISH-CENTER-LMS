<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Http\Controllers;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Modules\FeesManagement\Models\FeeInvoice;
use Plugins\Modules\PaymentManagement\Models\PaymentRefund;
use Plugins\Modules\PaymentManagement\Models\PaymentTransaction;
use Plugins\Modules\PaymentManagement\Services\PaymentSettlementService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentSettlementService $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $transactions = PaymentTransaction::query()
            ->with('invoice')
            ->when($actor && $actor->hasRole('branch_manager') && $actor->branch_id !== null, fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->latest()
            ->paginate(25);

        return view('payment-management::index', compact('transactions'));
    }

    public function recordOffline(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:fee_invoices,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'receipt_note' => ['nullable', 'string', 'max:160'],
        ]);

        $invoice = FeeInvoice::query()->findOrFail((int) $validated['invoice_id']);
        if ($actor && $actor->hasRole('branch_manager') && (int) $actor->branch_id !== (int) $invoice->branch_id) {
            abort(403, 'Branch isolation violation.');
        }

        $tx = PaymentTransaction::query()->create([
            'transaction_ref' => 'TXN-' . strtoupper(bin2hex(random_bytes(6))),
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'gateway_slug' => 'offline',
            'amount' => round((float) $validated['amount'], 2),
            'currency' => $invoice->currency,
            'status' => 'initiated',
            'metadata' => ['note' => $validated['receipt_note'] ?? 'Cash/Bank Desk'],
        ]);

        $this->settlement->settleTransaction($tx, 'OFFLINE-' . $tx->id, [], $actor?->id);

        return back()->with('status', "Offline payment {$tx->transaction_ref} recorded and settled.");
    }

    public function refund(Request $request, PaymentTransaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($transaction->status !== 'settled') {
            return back()->withErrors(['refund' => 'Only settled transactions can be refunded.']);
        }

        PaymentRefund::query()->create([
            'payment_transaction_id' => $transaction->id,
            'amount' => round((float) $validated['amount'], 2),
            'reason' => $validated['reason'],
            'approved_by' => $request->user()?->id,
        ]);

        $transaction->update(['status' => 'refunded']);
        $this->audit->log('payment.refunded', 'payment_transaction', (string) $transaction->id, ['amount' => $validated['amount']]);

        return back()->with('status', "Refund recorded for {$transaction->transaction_ref}.");
    }
}
