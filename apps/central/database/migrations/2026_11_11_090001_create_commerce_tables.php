<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2);
            $table->string('state', 80)->nullable();
            $table->string('kind', 20)->default('gst');   // gst, vat, none
            $table->unsignedSmallInteger('rate_basis_points'); // 1800 = 18.00%
            $table->boolean('inclusive')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country', 'state', 'kind']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('kind', 12)->default('percent'); // percent | fixed
            $table->unsignedInteger('value');               // percent basis points OR minor units
            $table->string('currency', 3)->nullable();      // required for fixed
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 24)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending, paid, failed, cancelled, refunded
            $table->string('currency', 3)->default('INR');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_order_id', 64)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('description', 160);
            $table->string('interval', 20);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('gateway_payment_id', 64)->unique();
            $table->string('gateway_order_id', 64)->nullable()->index();
            $table->string('status', 20)->default('captured')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 32)->nullable();
            $table->timestamp('signature_verified_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 32)->unique();     // INV/2026-27/0001
            $table->string('financial_year', 9);         // 2026-27
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('paid')->index(); // paid, refunded, void
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('place_of_supply', 2)->default('IN');
            $table->boolean('is_export')->default(false);
            $table->string('buyer_name', 160);
            $table->string('buyer_email', 160);
            $table->string('buyer_gstin', 15)->nullable();
            $table->json('seller_snapshot');
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 200);
            $table->string('sac_code', 8)->default('997331');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedBigInteger('taxable_minor');
            $table->unsignedBigInteger('cgst_minor')->default(0);
            $table->unsignedBigInteger('sgst_minor')->default(0);
            $table->unsignedBigInteger('igst_minor')->default(0);
            $table->unsignedSmallInteger('rate_basis_points')->default(1800);
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_refund_id', 64)->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('financial_ledger', function (Blueprint $table) {
            $table->id();
            $table->ulid('entry_group');                 // all postings of one transaction
            $table->string('account', 40)->index();      // cash_razorpay, revenue_licences, gst_payable_cgst…
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->string('currency', 3);
            $table->string('reference_type', 40);
            $table->string('reference_id', 64);
            $table->string('description', 200);
            $table->timestamp('posted_at')->index();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id', 128);
            $table->string('event_type', 64)->index();
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('received')->index(); // received, processed, failed, ignored
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('interval', 20);
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active')->index(); // trialing, active, past_due, cancelled, expired
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('financial_ledger');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('tax_rates');
    }
};
