<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesRequest extends FormRequest
{
    public function rules(): array
    {
        $lineRules = [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        return match ($this->route()?->getName()) {
            'sales.contacts.store', 'sales.contacts.update' => [
                'finance_contact_id' => ['nullable', 'integer', 'exists:finance_contacts,id'],
                'type' => ['required', Rule::in(['customer', 'vendor', 'both'])],
                'name' => ['required', 'string'],
                'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string'],
                'currency' => ['required', 'string', 'size:3'],
                'address' => ['nullable', 'array'],
            ],
            'sales.price-lists.store', 'sales.price-lists.update' => [
                'contact_id' => ['nullable', 'integer', 'exists:sales_contacts,id'],
                'name' => ['required', 'string'],
                'starts_on' => ['nullable', 'date'],
                'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
                'is_active' => ['required', 'boolean'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
                'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ],
            'sales.quotations.store', 'sales.quotations.update' => [
                'customer_id' => ['required', 'integer', 'exists:sales_contacts,id'],
                'number' => ['nullable', 'string', 'max:50'],
                'quote_date' => ['required', 'date'],
                'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
                'currency' => ['required', 'string', 'size:3'],
                'notes' => ['nullable', 'string'],
            ] + $lineRules,
            'sales.orders.store', 'sales.orders.update' => [
                'customer_id' => ['required', 'integer', 'exists:sales_contacts,id'],
                'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
                'number' => ['nullable', 'string', 'max:50'],
                'order_date' => ['required', 'date'],
                'expected_ship_date' => ['nullable', 'date', 'after_or_equal:order_date'],
                'currency' => ['required', 'string', 'size:3'],
                'notes' => ['nullable', 'string'],
            ] + $lineRules,
            'sales.purchase-orders.store', 'sales.purchase-orders.update' => [
                'vendor_id' => ['required', 'integer', 'exists:sales_contacts,id'],
                'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
                'number' => ['nullable', 'string', 'max:50'],
                'order_date' => ['required', 'date'],
                'expected_receipt_date' => ['nullable', 'date', 'after_or_equal:order_date'],
                'currency' => ['required', 'string', 'size:3'],
                'notes' => ['nullable', 'string'],
            ] + $lineRules,
            'sales.goods-receipts.store' => [
                'received_on' => ['required', 'date'],
                'notes' => ['nullable', 'string'],
                'lines' => ['nullable', 'array'],
                'lines.*.purchase_order_line_id' => ['required', 'integer', 'exists:sales_order_lines,id'],
                'lines.*.quantity_received' => ['required', 'numeric', 'min:0.0001'],
            ],
            'sales.match.store' => [
                'finance_contact_id' => ['nullable', 'integer', 'exists:finance_contacts,id'],
                'expense_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
                'invoice_number' => ['nullable', 'string', 'max:50'],
            ],
            'sales.orders.invoice' => [
                'finance_contact_id' => ['nullable', 'integer', 'exists:finance_contacts,id'],
                'revenue_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
                'invoice_number' => ['nullable', 'string', 'max:50'],
            ],
            default => [],
        };
    }
}
