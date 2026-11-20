<?php

namespace App\Console\Commands;

use App\Services\CustomerService;
use Illuminate\Console\Command;

class BackfillCustomers extends Command
{
    protected $signature = 'customers:backfill {--dry-run : Show what would happen without writing anything}';

    protected $description = 'Create the customers of existing orders and link the orders to them (idempotent)';

    public function handle(CustomerService $customers): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $customers->backfill($dry);

        $this->info($dry ? 'Dry run: nothing was written.' : 'Backfill done.');
        $this->table(['Orders without customer', 'Orders linked', 'Customers created', 'Customers total'], [[$result['orders'], $result['linked'], $result['customers_created'], $result['customers_total']]]);

        return self::SUCCESS;
    }
}
