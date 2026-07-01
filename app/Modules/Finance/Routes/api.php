<?php

use App\Modules\Finance\Http\Controllers\FinanceOperationsController;
use App\Modules\Finance\Http\Controllers\FinancialReportController;
use App\Modules\Finance\Http\Controllers\LedgerController;
use App\Modules\Finance\Http\Controllers\PayablesReceivablesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('finance')->name('finance.')->group(function () {
    Route::get('accounts', [LedgerController::class, 'accounts'])->name('accounts.index');
    Route::post('accounts', [LedgerController::class, 'storeAccount'])->name('accounts.store');
    Route::put('accounts/{account}', [LedgerController::class, 'updateAccount'])->name('accounts.update');
    Route::get('periods', [LedgerController::class, 'periods'])->name('periods.index');
    Route::post('periods', [LedgerController::class, 'storePeriod'])->name('periods.store');
    Route::post('periods/{period}/lock', [LedgerController::class, 'lockPeriod'])->name('periods.lock');
    Route::get('journals', [LedgerController::class, 'journals'])->name('journals.index');
    Route::post('journals', [LedgerController::class, 'storeJournal'])->name('journals.store');
    Route::post('journals/{journal}/post', [LedgerController::class, 'postJournal'])->name('journals.post');
    Route::get('contacts', [PayablesReceivablesController::class, 'contacts'])->name('contacts.index');
    Route::post('contacts', [PayablesReceivablesController::class, 'storeContact'])->name('contacts.store');
    Route::get('invoices', [PayablesReceivablesController::class, 'invoices'])->name('invoices.index');
    Route::post('invoices', [PayablesReceivablesController::class, 'storeInvoice'])->name('invoices.store');
    Route::post('invoices/{invoice}/post', [PayablesReceivablesController::class, 'postInvoice'])->name('invoices.post');
    Route::post('invoices/{invoice}/credit', [PayablesReceivablesController::class, 'creditInvoice'])->name('invoices.credit');
    Route::post('payments', [PayablesReceivablesController::class, 'storePayment'])->name('payments.store');
    Route::get('payment-schedules', [PayablesReceivablesController::class, 'schedules'])->name('payment-schedules.index');
    Route::post('payment-schedules', [PayablesReceivablesController::class, 'storeSchedule'])->name('payment-schedules.store');
    Route::get('taxes', [FinanceOperationsController::class, 'taxes'])->name('taxes.index');
    Route::post('taxes', [FinanceOperationsController::class, 'storeTax'])->name('taxes.store');
    Route::get('exchange-rates', [FinanceOperationsController::class, 'rates'])->name('exchange-rates.index');
    Route::post('exchange-rates', [FinanceOperationsController::class, 'storeRate'])->name('exchange-rates.store');
    Route::post('exchange-rates/sync', [FinanceOperationsController::class, 'syncRates'])->name('exchange-rates.sync');
    Route::get('bank-accounts', [FinanceOperationsController::class, 'banks'])->name('bank-accounts.index');
    Route::post('bank-accounts', [FinanceOperationsController::class, 'storeBank'])->name('bank-accounts.store');
    Route::post('bank-transactions', [FinanceOperationsController::class, 'storeTransaction'])->name('bank-transactions.store');
    Route::post('bank-transactions/{transaction}/reconcile', [FinanceOperationsController::class, 'reconcile'])->name('bank-transactions.reconcile');
    Route::get('budgets', [FinanceOperationsController::class, 'budgets'])->name('budgets.index');
    Route::post('budgets', [FinanceOperationsController::class, 'storeBudget'])->name('budgets.store');
    Route::get('budgets/{budget}/variance', [FinancialReportController::class, 'variance'])->name('budgets.variance');
    Route::get('reports/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('reports/tax-summary', [FinancialReportController::class, 'taxSummary'])->name('reports.tax-summary');
    Route::get('reports/payments', [FinancialReportController::class, 'paymentHistory'])->name('reports.payments');
    Route::get('reports/contacts/{contact}/statement', [FinancialReportController::class, 'contactStatement'])->name('reports.contacts.statement');
    Route::get('reports/aging/{type}', [FinancialReportController::class, 'aging'])->whereIn('type', ['payable', 'receivable'])->name('reports.aging');
    Route::get('reports/{statement}', [FinancialReportController::class, 'statement'])->whereIn('statement', ['balance-sheet', 'profit-loss', 'cash-flow'])->name('reports.statement');
});
