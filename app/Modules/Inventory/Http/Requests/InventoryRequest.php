<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'inventory.categories.store', 'inventory.categories.update' => ['name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:50'], 'parent_id' => ['nullable', 'integer', 'exists:product_categories,id']],
            'inventory.units.store', 'inventory.units.update' => ['name' => ['required', 'string', 'max:100'], 'symbol' => ['required', 'string', 'max:20'], 'precision' => ['sometimes', 'integer', 'min:0', 'max:6']],
            'inventory.products.store', 'inventory.products.update' => [
                'category_id' => ['nullable', 'integer', 'exists:product_categories,id'], 'unit_id' => ['required', 'integer', 'exists:units,id'],
                'sku' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'],
                'barcode' => ['nullable', 'string', 'max:150'], 'qr_code' => ['nullable', 'string', 'max:255'],
                'sale_price' => ['required', 'numeric', 'min:0'], 'purchase_price' => ['required', 'numeric', 'min:0'],
                'valuation_method' => ['required', Rule::in(['fifo', 'lifo', 'average'])], 'is_active' => ['sometimes', 'boolean'],
            ],
            'inventory.warehouses.store', 'inventory.warehouses.update' => ['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'array'], 'is_active' => ['sometimes', 'boolean']],
            'inventory.locations.store', 'inventory.locations.update' => ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:255']],
            'inventory.balances.reorder.update' => ['reorder_point' => ['required', 'numeric', 'min:0'], 'reorder_quantity' => ['required', 'numeric', 'min:0']],
            'inventory.movements.store' => [
                'type' => ['required', Rule::in(['receipt', 'issue', 'transfer', 'adjust_in', 'adjust_out', 'write_off'])],
                'reason_code' => ['required_if:type,adjust_in,adjust_out,write_off', 'nullable', 'string', 'max:100'], 'reference' => ['nullable', 'string', 'max:150'],
                'notes' => ['nullable', 'string'], 'occurred_at' => ['nullable', 'date'], 'lines' => ['required', 'array', 'min:1'],
                'lines.*.product_id' => ['required', 'integer', 'exists:products,id'], 'lines.*.from_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
                'lines.*.from_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'], 'lines.*.to_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
                'lines.*.to_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
                'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            ],
            default => [],
        };
    }
}
