<?php

namespace App\Modules\POS\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A till: the point where a warehouse, a cashier and a cash drawer meet.
 *
 * The register owns the stock source, so a sale never has to guess which
 * warehouse it is depleting.
 */
class PosRegister extends Model
{
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'location_id',
        'code',
        'name',
        'currency',
        'receipt_prefix',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PosRegisterAssignment::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PosRegisterPaymentMethod::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'pos_register_assignments')
            ->withPivot(['role', 'starts_on', 'ends_on'])
            ->withTimestamps();
    }

    /** Cash counted at close may differ from expected by at most this much. */
    public function varianceThreshold(): string
    {
        return (string) data_get($this->settings, 'shift.variance_threshold', '0');
    }

    public function allowsNegativeStock(): bool
    {
        // Negative inventory cannot be enabled until receipts reconcile the
        // shortage layer and its COGS variance. Keep legacy settings inert.
        return false;
    }

    public function defaultContactId(): ?int
    {
        $id = data_get($this->settings, 'customer.walk_in_contact_id');

        return $id === null ? null : (int) $id;
    }
}
