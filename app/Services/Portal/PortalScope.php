<?php

namespace App\Services\Portal;

use App\Models\Booking;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPayment;
use App\Models\CustomerSubscription;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\ProgressBilling;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only place portal controllers get records from. Every query starts at the signed-in portal
 * user's own customer, so a controller cannot forget the ownership filter; a record of another
 * customer (or company) simply is not found (404), which also hides that it exists.
 */
class PortalScope
{
    /** Contract statuses a customer may see once staff shared the contract. */
    public const CONTRACT_STATUSES = ['approved', 'active', 'expired', 'terminated', 'renewed'];

    public function __construct(public readonly PortalUser $user) {}

    public function customer(): Customer
    {
        return Customer::findOrFail($this->user->customer_id);
    }

    public function invoices(): Builder
    {
        return Invoice::query()->where('customer_id', $this->user->customer_id);
    }

    public function payments(): Builder
    {
        return CustomerPayment::query()->where('customer_id', $this->user->customer_id)->where('status', '!=', 'cancelled');
    }

    public function tickets(): Builder
    {
        return Ticket::query()->where('customer_id', $this->user->customer_id);
    }

    public function contracts(): Builder
    {
        return Contract::query()->where('party_type', 'customer')->where('customer_id', $this->user->customer_id)
            ->where('shared_with_customer', true)->whereIn('status', self::CONTRACT_STATUSES);
    }

    public function subscriptions(): Builder
    {
        return CustomerSubscription::query()->where('customer_id', $this->user->customer_id);
    }

    public function projects(): Builder
    {
        return Project::query()->where('customer_id', $this->user->customer_id);
    }

    /** Progress billings of the customer's projects that were approved. */
    public function billings(): Builder
    {
        return ProgressBilling::query()->where('status', 'approved')->whereIn('project_id', $this->projects()->select('id'));
    }

    public function bookings(): Builder
    {
        return Booking::query()->where('customer_id', $this->user->customer_id);
    }

    public function packages(): Builder
    {
        return CustomerPackage::query()->where('customer_id', $this->user->customer_id);
    }

    public function workOrders(): Builder
    {
        return WorkOrder::query()->where('customer_id', $this->user->customer_id)->where('status', '!=', 'cancelled');
    }
}
