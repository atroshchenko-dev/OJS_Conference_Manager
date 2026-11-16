<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** PAY-09: card payment attempts through a payment gateway, one row per attempt. */
final class Step0018GatewayPayments implements SchemaStep
{
    public function id(): string
    {
        return '0018_gateway_payments';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if ($schema->hasTable('conf_gateway_payments')) {
            return;
        }
        $schema->create('conf_gateway_payments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('context_id');
            $t->bigInteger('invoice_id')->unsigned();
            $t->foreign('invoice_id', 'conf_gateway_payments_invoice_fk')
                ->references('id')->on('conf_invoices')->restrictOnDelete();
            $t->string('gateway', 32);
            $t->string('order_id', 64);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status', 16)->default('created');
            $t->string('transaction_id', 64)->nullable();
            $t->text('details')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->unique(['gateway', 'order_id'], 'conf_gateway_payments_order');
            $t->index(['invoice_id'], 'conf_gateway_payments_invoice');
            $t->index(['status', 'created_at'], 'conf_gateway_payments_open');
        });
    }
}
