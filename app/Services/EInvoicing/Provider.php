<?php

namespace App\Services\EInvoicing;

use Illuminate\Database\Eloquent\Model;

/**
 * A government e-invoicing system (ZATCA in Saudi Arabia, ETA in Egypt,
 * JoFotara in Jordan, ...). The branch's country decides which one stamps
 * and submits a sales document.
 */
interface Provider
{
    /** Key used in config/countries.php ("zatca", "eta", ...). */
    public function key(): string;

    public function label(): string;

    /** Whether the system is integrated (documents are signed and submitted) or only planned. */
    public function integrated(): bool;

    /** Stamp a newly issued sales document (QR, signature, submission queue). */
    public function stamp(Model $document): void;
}
