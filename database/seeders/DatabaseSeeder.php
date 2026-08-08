<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Cafe;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\GameSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MembershipTier;
use App\Models\Package;
use App\Models\Product;
use App\Models\Station;
use App\Models\StationRate;
use App\Services\BillingService;
use App\Services\StationTokenService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * §9 — a demo cafe with three weeks of trading behind it.
 *
 * Idempotent: if the cafe already has stations, this does nothing, so it is
 * safe to run on every deploy.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $cafe = Cafe::firstOrCreate(
            ['slug' => 'my-gaming-cafe'],
            ['name' => 'My Gaming Cafe', 'is_active' => true, 'created_at' => now()],
        );

        if (Station::where('cafe_id', $cafe->id)->exists()) {
            $this->command?->info('CafeTrack demo data already present — skipping.');

            return;
        }

        $this->accounts($cafe);
        $stations = $this->stations($cafe);
        $products = $this->products($cafe);
        $this->packages($cafe);
        $this->tiers($cafe);
        $customers = $this->customers($cafe);
        $this->history($cafe, $stations, $customers, $products);
        $this->expenses($cafe);

        $this->command?->info('CafeTrack demo data seeded.');
    }

    private function accounts(Cafe $cafe): void
    {
        // The platform owner belongs to NO cafe. cafe_id must stay null here —
        // see the note on the admin_users migration.
        AdminUser::firstOrCreate(
            ['email' => 'owner@cafetrack.test'],
            [
                'cafe_id' => null,
                'password_hash' => Hash::make('password'),
                'role' => 'superadmin',
                'created_at' => now(),
            ],
        );

        AdminUser::firstOrCreate(
            ['email' => 'admin@cafetrack.test'],
            [
                'cafe_id' => $cafe->id,
                'password_hash' => Hash::make('password'),
                'role' => 'admin',
                'created_at' => now(),
            ],
        );

        AdminUser::firstOrCreate(
            ['email' => 'staff@cafetrack.test'],
            [
                'cafe_id' => $cafe->id,
                'password_hash' => Hash::make('password'),
                'role' => 'staff',
                'created_at' => now(),
            ],
        );
    }

    /** @return Collection<int, Station> */
    private function stations(Cafe $cafe)
    {
        $qr = app(StationTokenService::class);

        /*
         * Rates per controller count, taken from a real cafe's price list.
         *
         * Note that PS4 and PS5 do not step evenly — the second pad adds less
         * than the third and fourth do. That is exactly the shape the old
         * base-plus-flat-extra model could not express, and why the rates live
         * in their own table.
         */
        $definitions = [];

        for ($i = 1; $i <= 2; $i++) {
            $definitions[] = ["PS4 - Booth {$i}", 'PS4', [1 => '100.00', 2 => '120.00', 3 => '160.00', 4 => '200.00']];
        }

        for ($i = 1; $i <= 4; $i++) {
            $definitions[] = ["PS5 - Booth {$i}", 'PS5', [1 => '160.00', 2 => '200.00', 3 => '260.00', 4 => '320.00']];
        }

        $definitions[] = ['PS5 Pro - Lounge', 'PS5Pro', [1 => '240.00', 2 => '300.00', 3 => '360.00', 4 => '420.00']];
        $definitions[] = ['Racing Wheel', 'Wheel', [1 => '400.00']];

        return collect($definitions)->map(function (array $d) use ($cafe, $qr) {
            [$name, $type, $rates] = $d;

            $station = Station::create([
                'cafe_id' => $cafe->id,
                'name' => $name,
                'type' => $type,
                'hourly_rate' => $rates[1],
                'max_controllers' => count($rates),
                'is_active' => true,
                'created_at' => now(),
            ]);

            $station->qr_code_url = $qr->checkinUrl($station->id);
            $station->save();

            foreach ($rates as $controllers => $rate) {
                StationRate::create([
                    'station_id' => $station->id,
                    'controllers' => $controllers,
                    'hourly_rate' => $rate,
                ]);
            }

            return $station->load('rates');
        });
    }

    /**
     * A month of running costs, so the summary sheets have something to net
     * off. None are linked to a shift: they are historical, and the drawer
     * they came out of was counted long ago.
     */
    private function expenses(Cafe $cafe): void
    {
        $rows = [
            [28, 'rent', '18000.00', 'bank', 'Monthly rent'],
            [28, 'salary', '32000.00', 'bank', 'Staff wages'],
            [21, 'utilities', '4200.00', 'bank', 'Electricity'],
            [21, 'internet', '3500.00', 'bank', 'Fibre line'],
            [14, 'stock', '2650.00', 'cash', 'Soft drinks and crisps'],
            [11, 'maintenance', '1800.00', 'cash', 'PS5 fan cleaning'],
            [7, 'equipment', '5400.00', 'phone_payment', 'Two replacement controllers'],
            [5, 'stock', '1950.00', 'cash', 'Snacks restock'],
            [3, 'transport', '600.00', 'cash', 'Courier for the wheel pedals'],
            [1, 'marketing', '2500.00', 'phone_payment', 'Boosted a launch post'],
        ];

        foreach ($rows as [$daysAgo, $category, $amount, $method, $note]) {
            Expense::create([
                'cafe_id' => $cafe->id,
                'category' => $category,
                'amount' => $amount,
                'payment_method' => $method,
                'note' => $note,
                'spent_on' => now()->subDays($daysAgo)->toDateString(),
                'actor_email' => 'admin@cafetrack.test',
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    /** @return Collection<int, Product> */
    private function products(Cafe $cafe)
    {
        $rows = [
            ['Coca-Cola 250ml', 'Drinks', '40.00', '28.00'],
            ['Sprite 250ml', 'Drinks', '40.00', '28.00'],
            ['Mineral Water 500ml', 'Drinks', '20.00', '12.00'],
            ['Energy Drink', 'Drinks', '90.00', '65.00'],
            ['Cold Coffee', 'Drinks', '110.00', '62.00'],
            ['Potato Chips', 'Snacks', '35.00', '24.00'],
            ['Nachos with Cheese', 'Snacks', '150.00', '88.00'],
            ['French Fries', 'Snacks', '120.00', '58.00'],
            ['Chicken Sandwich', 'Food', '220.00', '135.00'],
            ['Chicken Burger', 'Food', '260.00', '158.00'],
            ['Beef Shawarma', 'Food', '240.00', '150.00'],
            ['Instant Noodles', 'Food', '90.00', '48.00'],
        ];

        return collect($rows)->map(fn (array $r) => Product::create([
            'cafe_id' => $cafe->id,
            'name' => $r[0],
            'category' => $r[1],
            'price' => $r[2],
            'cost_price' => $r[3],
            'is_active' => true,
            'created_at' => now(),
        ]));
    }

    private function packages(Cafe $cafe): void
    {
        // The bonus is the point: pay 1000, play 1150.
        foreach ([['Starter', '500.00', '550.00'], ['Regular', '1000.00', '1150.00'], ['Pro', '2000.00', '2400.00']] as $p) {
            Package::create([
                'cafe_id' => $cafe->id,
                'name' => $p[0],
                'price' => $p[1],
                'credit' => $p[2],
                'is_active' => true,
                'created_at' => now(),
            ]);
        }
    }

    private function tiers(Cafe $cafe): void
    {
        foreach ([['Bronze', '0.00', '0.00'], ['Silver', '3000.00', '5.00'], ['Gold', '10000.00', '10.00']] as $t) {
            MembershipTier::create([
                'cafe_id' => $cafe->id,
                'name' => $t[0],
                'min_spend' => $t[1],
                'discount_percent' => $t[2],
                'is_active' => true,
                'created_at' => now(),
            ]);
        }
    }

    /** @return Collection<int, Customer> */
    private function customers(Cafe $cafe)
    {
        $rows = [
            ['Rafi Ahmed', '01711000001', '0.00'],
            ['Nabila Haque', '01711000002', '350.00'],
            ['Tanvir Islam', '01711000003', '0.00'],
            ['সাদিয়া রহমান', '01711000004', '1150.00'],
            ['Arif Chowdhury', '01711000005', '0.00'],
            ['Mehedi Hasan', '01711000006', '80.00'],
        ];

        return collect($rows)->map(fn (array $r) => Customer::create([
            'cafe_id' => $cafe->id,
            'name' => $r[0],
            'phone_or_id' => $r[1],
            'balance' => $r[2],
            'created_at' => now()->subDays(30),
        ]));
    }

    /** Three weeks of past sessions, each with an invoice, some with snacks. */
    private function history(Cafe $cafe, $stations, $customers, $products): void
    {
        $billing = app(BillingService::class);
        $block = 15;
        $step = 5;

        mt_srand(20260807); // stable demo data across re-seeds

        for ($daysAgo = 21; $daysAgo >= 1; $daysAgo--) {
            $sessionsToday = mt_rand(4, 11);

            for ($i = 0; $i < $sessionsToday; $i++) {
                $station = $stations->random();
                $customer = $customers->random();

                $startHour = mt_rand(11, 21);
                $start = now()->subDays($daysAgo)->setTime($startHour, mt_rand(0, 59));
                $minutes = mt_rand(25, 190);
                $end = $start->copy()->addMinutes($minutes);

                $controllers = $station->max_controllers > 1 ? mt_rand(1, $station->max_controllers) : 1;

                $rate = $billing->effectiveRate($station->rateMap(), $controllers, $station->hourly_rate);

                $session = GameSession::create([
                    'cafe_id' => $cafe->id,
                    'station_id' => $station->id,
                    'customer_id' => $customer->id,
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => 'completed',
                    'hourly_rate_snapshot' => (string) $rate,
                    'base_rate_snapshot' => (string) Money::round($station->hourly_rate),
                    'extra_controller_rate_snapshot' => (string) $billing->averageExtraRate(
                        $rate,
                        $station->hourly_rate,
                        $controllers,
                    ),
                    'controllers' => $controllers,
                    'created_at' => $start,
                ]);

                $billed = $billing->billedMinutes($minutes, $block);
                $sessionAmount = $billing->roundToStep($billing->grossAmount($billed, $rate), $step);

                $invoice = Invoice::create([
                    'cafe_id' => $cafe->id,
                    'session_id' => $session->id,
                    'session_amount' => (string) $sessionAmount,
                    'items_amount' => '0.00',
                    'discount_amount' => '0.00',
                    'total_amount' => (string) $sessionAmount,
                    'duration_minutes' => $billed,
                    'payment_method' => mt_rand(0, 1) === 1 ? 'cash' : 'phone_payment',
                    'payment_status' => 'paid',
                    'status' => 'active',
                    'paid_at' => $end,
                    'created_at' => $end,
                ]);

                // Roughly half the sessions pick up snacks on the way out.
                if (mt_rand(0, 1) === 1) {
                    $itemsTotal = Money::zero();

                    foreach (range(1, mt_rand(1, 3)) as $ignored) {
                        $product = $products->random();
                        $quantity = mt_rand(1, 2);
                        $amount = Money::round(Money::of($product->price)->multipliedBy($quantity));

                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'product_id' => $product->id,
                            'description' => $product->name,
                            'quantity' => $quantity,
                            'unit_price' => (string) Money::round($product->price),
                            'unit_cost' => (string) Money::round($product->cost_price),
                            'amount' => (string) $amount,
                            'created_at' => $end,
                        ]);

                        $itemsTotal = $itemsTotal->plus($amount);
                    }

                    $invoice->items_amount = (string) $itemsTotal;
                    $invoice->total_amount = (string) Money::round($sessionAmount->plus($itemsTotal));
                    $invoice->save();
                }
            }
        }
    }
}
