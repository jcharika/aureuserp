<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Employee\Models\Employee;
use Webkul\Maintenance\Models\Equipment;
use Webkul\Maintenance\Models\EquipmentCategory;
use Webkul\Maintenance\Models\MaintenanceRequest;
use Webkul\Maintenance\Models\Stage as MaintenanceStage;
use Webkul\Maintenance\Models\Team as MaintenanceTeam;
use Webkul\Partner\Enums\AccountType;
use Webkul\Partner\Models\Partner;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Product\Database\Seeders\DatabaseSeeder;
use Webkul\Product\Enums\ProductType;
use Webkul\Product\Models\Category as ProductCategory;
use Webkul\Product\Models\Product;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\ProjectStage;
use Webkul\Project\Models\Task;
use Webkul\Project\Models\TaskStage;
use Webkul\Sale\Enums\InvoiceStatus;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\Order as SalesOrder;
use Webkul\Sale\Models\OrderLine as SalesOrderLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;

class DemoSeeder extends Seeder
{
    /**
     * Seed clean active modules and create Zimbabwean construction demo records.
     */
    public function run(): void
    {
        if (! Schema::hasTable('plugins')) {
            $this->command?->warn('Skipping demo seeding because the plugins table does not exist.');

            return;
        }

        $activeModules = Plugin::query()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->pluck('name')
            ->all();

        $this->seedEmptyActiveModules($activeModules);

        $company = $this->seedCompany();

        if (! $company) {
            $this->command?->warn('Stopping the demo seeder because the Zimbabwe demo company could not be created.');

            return;
        }

        $this->seedContacts($activeModules, $company);
        $customer = Partner::withoutGlobalScopes()
            ->where('reference', 'SSC-ZW-CUSTOMER-001')
            ->first();

        if (in_array('employees', $activeModules, true)) {
            $this->seedEmployees($company);
        }

        if (in_array('products', $activeModules, true)) {
            $this->seedProducts($company);
        }

        if (in_array('sales', $activeModules, true) && in_array('products', $activeModules, true)) {
            $this->seedSalesOrder($company, $customer);
        }

        if (in_array('projects', $activeModules, true)) {
            $project = $this->seedProject($company, $customer);

            if ($project && in_array('maintenance', $activeModules, true)) {
                $this->seedMaintenance($company, $customer);
            }
        }
    }

    /**
     * @param  array<int, string>  $activeModules
     */
    private function seedEmptyActiveModules(array $activeModules): void
    {
        $moduleSeeders = [
            'products'      => [DatabaseSeeder::class, 'products_', 'products'],
            'accounts'      => [\Webkul\Account\Database\Seeders\DatabaseSeeder::class, 'accounts_', 'accounts'],
            'inventories'   => [\Webkul\Inventory\Database\Seeders\DatabaseSeeder::class, 'inventories_', 'inventories'],
            'employees'     => [\Webkul\Employee\Database\Seeders\DatabaseSeeder::class, 'employees_', 'employees'],
            'projects'      => [\Webkul\Project\Database\Seeders\DatabaseSeeder::class, 'projects_', 'projects'],
            'sales'         => [\Webkul\Sale\Database\Seeders\DatabaseSeeder::class, 'sales_', 'sales'],
            'purchases'     => [\Webkul\Purchase\Database\Seeders\DatabaseSeeder::class, 'purchases_', 'purchases'],
            'invoices'      => [\Webkul\Invoice\Database\Seeders\DatabaseSeeder::class, 'invoices_', 'invoices'],
            'maintenance'   => [\Webkul\Maintenance\Database\Seeders\DatabaseSeeder::class, 'maintenance_', 'maintenance'],
            'manufacturing' => [\Webkul\Manufacturing\Database\Seeders\DatabaseSeeder::class, 'manufacturing_', 'manufacturing'],
            'payments'      => [\Webkul\Payment\Database\Seeders\DatabaseSeeder::class, 'payments_', 'payments'],
            'point-of-sale' => [\Webkul\PointOfSale\Database\Seeders\DatabaseSeeder::class, 'point_of_sale_', 'point-of-sale'],
            'recruitments'  => [\Webkul\Recruitment\Database\Seeders\DatabaseSeeder::class, 'recruitments_', 'recruitments'],
            'time-off'      => [\Webkul\TimeOff\Database\Seeders\DatabaseSeeder::class, 'time_off_', 'time-off'],
            'website'       => [\Webkul\Website\Database\Seeders\DatabaseSeeder::class, 'website_', 'website'],
        ];

        foreach ($moduleSeeders as $module => [$seeder, $tablePrefix, $pluginDirectory]) {
            if (! in_array($module, $activeModules, true)) {
                continue;
            }

            $seederPath = base_path("plugins/webkul/{$pluginDirectory}/database/seeders/DatabaseSeeder.php");

            if (! is_file($seederPath)) {
                $this->command?->warn("Skipping {$module} defaults because its module seeder file is unavailable.");

                continue;
            }

            require_once $seederPath;

            if ($this->moduleHasRows($tablePrefix)) {
                $this->command?->warn("Skipping {$module} defaults because module data already exists.");

                continue;
            }

            $this->call($seeder);
        }
    }

    private function moduleHasRows(string $tablePrefix): bool
    {
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! str_starts_with($table, $tablePrefix)) {
                continue;
            }

            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function seedCompany(): ?Company
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('partners_partners')) {
            $this->command?->warn('Skipping Zimbabwe company demo data because company or contact tables are missing.');

            return null;
        }

        return DB::transaction(function (): Company {
            $userId = Schema::hasTable('users') ? User::query()->value('id') : null;
            $country = Schema::hasTable('countries')
                ? Country::query()->where('code', 'ZW')->first()
                : null;
            $currency = Schema::hasTable('currencies')
                ? Currency::findByCode(config('app.currency'))
                : null;

            $headOfficePartner = Partner::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                    ['reference' => 'SSC-ZW-HEAD-OFFICE'],
                    [
                        'account_type'     => AccountType::COMPANY->value,
                        'sub_type'         => 'partner',
                        'name'             => 'Stand Servicing & Construction (Pvt) Ltd',
                        'email'            => 'info@standservicing.example',
                        'phone'            => '+263 242 700 001',
                        'mobile'           => '+263 772 700 001',
                        'company_registry' => 'DEMO-REG-ZW-001',
                        'creator_id'       => $userId,
                    ],
                );

            $company = Company::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                    ['company_id' => 'SSC-ZW-001'],
                    [
                        'sort'                => 1,
                        'name'                => 'Stand Servicing & Construction (Pvt) Ltd',
                        'tax_id'              => 'DEMO-TAX-ZW-001',
                        'registration_number' => 'DEMO-REG-ZW-001',
                        'email'               => 'info@standservicing.example',
                        'phone'               => '+263 242 700 001',
                        'mobile'              => '+263 772 700 001',
                        'street1'             => 'Samora Machel Avenue',
                        'street2'             => 'Harare CBD',
                        'city'                => 'Harare',
                        'zip'                 => null,
                        'country_id'          => $country?->id,
                        'color'               => '#173C73',
                        'is_active'           => true,
                        'founded_date'        => '2018-01-01',
                        'currency_id'         => $currency?->id,
                        'partner_id'          => $headOfficePartner->id,
                        'website'             => 'https://standservicing.example',
                    ],
                );

            $headOfficePartner->forceFill(['company_id' => $company->id])->save();

            return $company;
        });
    }

    /**
     * @param  array<int, string>  $activeModules
     */
    private function seedContacts(array $activeModules, Company $company): void
    {
        if (! in_array('contacts', $activeModules, true)) {
            $this->command?->warn('Skipping demo contacts because the contacts module is not active and installed.');

            return;
        }

        if (! Schema::hasTable('partners_partners')) {
            $this->command?->warn('Skipping demo contacts because the contacts table is missing.');

            return;
        }

        $contacts = [
            [
                'reference' => 'SSC-ZW-CUSTOMER-001',
                'name'      => 'Goromonzi Housing Cooperative',
                'email'     => 'projects@goromonzi-cooperative.example',
                'phone'     => '+263 772 410 101',
            ],
            [
                'reference' => 'SSC-ZW-CUSTOMER-002',
                'name'      => 'Ruwa Residential Development Trust',
                'email'     => 'procurement@ruwa-homes.example',
                'phone'     => '+263 772 410 102',
            ],
            [
                'reference' => 'SSC-ZW-SUPPLIER-001',
                'name'      => 'Harare Building Materials Supply',
                'email'     => 'sales@harare-building-supplies.example',
                'phone'     => '+263 242 710 110',
            ],
        ];

        foreach ($contacts as $contact) {
            Partner::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                    ['reference' => $contact['reference']],
                    [
                        'account_type' => AccountType::COMPANY->value,
                        'sub_type'     => 'partner',
                        'name'         => $contact['name'],
                        'email'        => $contact['email'],
                        'phone'        => $contact['phone'],
                        'company_id'   => $company->id,
                    ],
                );
        }
    }

    private function seedProducts(Company $company): void
    {
        if (! Schema::hasTable('products_products') || ! Schema::hasTable('products_categories') || ! Schema::hasTable('unit_of_measures')) {
            $this->command?->warn('Skipping construction products because one or more product tables are missing.');

            return;
        }

        $category = ProductCategory::query()->where('name', 'Home Construction')->first()
            ?? ProductCategory::query()->first();
        $unit = UOM::query()->where('name', 'Units')->first()
            ?? UOM::query()->first();

        if (! $category || ! $unit) {
            $this->command?->warn('Skipping construction products because a product category or unit of measure is missing.');

            return;
        }

        $products = [
            ['SSC-STAND-SERVICING', 'Residential Stand Servicing Package', 1250.00, 300.00],
            ['SSC-ROAD-DRAINAGE', 'Access Road & Storm-water Works', 18500.00, 14200.00],
            ['SSC-WATER-RETICULATION', 'Stand Water Reticulation Connection', 980.00, 650.00],
            ['SSC-STARTER-HOME', 'Two-bedroom Starter Home Construction', 38500.00, 31200.00],
        ];

        foreach ($products as [$reference, $name, $price, $cost]) {
            Product::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                    ['reference' => $reference],
                    [
                        'type'                 => ProductType::SERVICE,
                        'name'                 => $name,
                        'price'                => $price,
                        'cost'                 => $cost,
                        'description'          => 'Demo construction service delivered in Harare and surrounding Zimbabwe communities.',
                        'description_purchase' => 'Costed using demo Zimbabwe supplier pricing.',
                        'description_sale'     => 'Includes site coordination and project delivery milestones.',
                        'enable_sales'         => true,
                        'enable_purchase'      => false,
                        'sort'                 => 1,
                        'category_id'          => $category->id,
                        'uom_id'               => $unit->id,
                        'uom_po_id'            => $unit->id,
                        'company_id'           => $company->id,
                    ],
                );
        }
    }

    private function seedEmployees(Company $company): void
    {
        if (! Schema::hasTable('employees_employees')) {
            $this->command?->warn('Skipping demo employees because the employees table is missing.');

            return;
        }

        $countryId = Schema::hasTable('countries')
            ? Country::query()->where('code', 'ZW')->value('id')
            : null;

        $employees = [
            ['tendai.moyo@standservicing.example', 'Tendai Moyo', 'Operations Director', '+263 772 510 001'],
            ['nyasha.dube@standservicing.example', 'Nyasha Dube', 'Site Engineer', '+263 772 510 002'],
            ['tinashe.chikomba@standservicing.example', 'Tinashe Chikomba', 'Quantity Surveyor', '+263 772 510 003'],
        ];

        foreach ($employees as [$email, $name, $jobTitle, $phone]) {
            Employee::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                    ['work_email' => $email],
                    [
                        'name'          => $name,
                        'job_title'     => $jobTitle,
                        'work_phone'    => $phone,
                        'time_zone'     => 'Africa/Harare',
                        'country_id'    => $countryId,
                        'company_id'    => $company->id,
                        'employee_type' => 'employee',
                        'is_active'     => true,
                    ],
                );
        }
    }

    private function seedSalesOrder(Company $company, ?Partner $customer): void
    {
        if (! $customer || ! Schema::hasTable('sales_orders') || ! Schema::hasTable('sales_order_lines')) {
            $this->command?->warn('Skipping demo quotation because the customer or sales tables are missing.');

            return;
        }

        $product = Product::withoutGlobalScopes()
            ->where('reference', 'SSC-STAND-SERVICING')
            ->first();
        $userId = Schema::hasTable('users') ? User::query()->value('id') : null;
        $currencyId = $company->currency_id ?? default_currency_id();

        if (! $product || ! $userId || ! $currencyId) {
            $this->command?->warn('Skipping demo quotation because a product, user, or currency is missing.');

            return;
        }

        $quantity = 48;
        $subtotal = (float) $product->price * $quantity;

        $order = SalesOrder::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                ['name' => 'SSC-DEMO-QUOTATION-001'],
                [
                    'company_id'          => $company->id,
                    'partner_id'          => $customer->id,
                    'partner_invoice_id'  => $customer->id,
                    'partner_shipping_id' => $customer->id,
                    'currency_id'         => $currencyId,
                    'user_id'             => $userId,
                    'creator_id'          => $userId,
                    'state'               => OrderState::DRAFT,
                    'invoice_status'      => InvoiceStatus::TO_INVOICE,
                    'client_order_ref'    => 'GHC-48-STANDS-DEMO',
                    'origin'              => 'Goromonzi 48-Stand Residential Scheme',
                    'note'                => 'Demo quotation for site servicing and construction. Values are illustrative.',
                    'currency_rate'       => 1,
                    'amount_untaxed'      => $subtotal,
                    'amount_tax'          => 0,
                    'amount_total'        => $subtotal,
                    'locked'              => false,
                    'require_signature'   => false,
                    'require_payment'     => false,
                    'date_order'          => now()->toDateString(),
                ],
            );

        SalesOrderLine::withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                ],
                [
                    'company_id'       => $company->id,
                    'currency_id'      => $currencyId,
                    'order_partner_id' => $customer->id,
                    'salesman_id'      => $userId,
                    'product_uom_id'   => $product->uom_id,
                    'state'            => OrderState::DRAFT,
                    'invoice_status'   => InvoiceStatus::TO_INVOICE,
                    'name'             => $product->name,
                    'product_uom_qty'  => $quantity,
                    'product_qty'      => $quantity,
                    'price_unit'       => $product->price,
                    'price_subtotal'   => $subtotal,
                    'price_total'      => $subtotal,
                ],
            );
    }

    private function seedProject(Company $company, ?Partner $customer): ?Project
    {
        if (! Schema::hasTable('projects_projects') || ! Schema::hasTable('projects_project_stages') || ! Schema::hasTable('projects_tasks') || ! $customer) {
            $this->command?->warn('Skipping the demo project because the customer or one or more project tables are missing.');

            return null;
        }

        $stage = ProjectStage::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                ['name' => 'Site Servicing', 'company_id' => $company->id],
                ['is_active' => true, 'is_collapsed' => false, 'sort' => 10],
            );

        $project = Project::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                [
                    'name'       => 'Goromonzi 48-Stand Residential Scheme',
                    'company_id' => $company->id,
                ],
                [
                    'tasks_label'             => 'Construction activities',
                    'description'             => 'Survey, service and construct a 48-stand residential development in Goromonzi, Mashonaland East, Zimbabwe.',
                    'visibility'              => 'public',
                    'color'                   => '#35C8B5',
                    'sort'                    => 10,
                    'start_date'              => '2026-01-05',
                    'end_date'                => '2026-12-18',
                    'allocated_hours'         => 5200,
                    'allow_timesheets'        => true,
                    'allow_milestones'        => true,
                    'allow_task_dependencies' => true,
                    'is_active'               => true,
                    'stage_id'                => $stage->id,
                    'partner_id'              => $customer->id,
                ],
            );

        if (! Schema::hasTable('projects_task_stages')) {
            $this->command?->warn('Created the demo project but skipped its tasks because the task stages table is missing.');

            return $project;
        }

        $taskStage = TaskStage::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                ['name' => 'In Progress', 'project_id' => $project->id],
                ['is_active' => true, 'is_collapsed' => false, 'company_id' => $company->id],
            );

        $tasks = [
            ['Peg and survey 48 residential stands', 'Complete boundary verification, stand numbering and survey pegs.', 0.25],
            ['Grade access roads and install storm-water drains', 'Shape internal roads and direct storm-water away from residential plots.', 0.15],
            ['Install water and sewer reticulation', 'Connect each stand to the planned water and sewer network.', 0.10],
            ['Build the two-bedroom demonstration home', 'Construct a model home to demonstrate the project finish and specifications.', 0.00],
        ];

        $creatorId = User::query()->value('id');

        if (! $creatorId) {
            $this->command?->warn('Skipping demo project tasks because no user is available as the task creator.');

            return $project;
        }

        foreach ($tasks as [$title, $description, $progress]) {
            Task::withoutGlobalScopes()
                ->withTrashed()
                ->updateOrCreate(
                    ['title' => $title, 'project_id' => $project->id],
                    [
                        'description'     => $description,
                        'color'           => '#173C73',
                        'priority'        => $progress === 0.25,
                        'state'           => 'in_progress',
                        'sort'            => 10,
                        'is_active'       => true,
                        'is_recurring'    => false,
                        'deadline'        => now()->addMonths(2),
                        'allocated_hours' => 720,
                        'remaining_hours' => 720 * (1 - $progress),
                        'progress'        => $progress * 100,
                        'stage_id'        => $taskStage->id,
                        'partner_id'      => $customer->id,
                        'company_id'      => $company->id,
                        'creator_id'      => $creatorId,
                    ],
                );
        }

        return $project;
    }

    private function seedMaintenance(Company $company, ?Partner $customer): void
    {
        if (! $customer || ! Schema::hasTable('maintenance_equipments') || ! Schema::hasTable('maintenance_requests')) {
            $this->command?->warn('Skipping demo maintenance because the customer or maintenance tables are missing.');

            return;
        }

        $team = MaintenanceTeam::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                ['name' => 'Stand Servicing Crew', 'company_id' => $company->id],
                [],
            );

        $equipmentCategory = EquipmentCategory::query()->firstOrCreate(
            ['name' => 'Earthmoving Equipment'],
            [],
        );

        $equipment = Equipment::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                ['partner_ref' => 'SSC-EQ-001'],
                [
                    'name'                   => 'JCB 3CX Backhoe Loader',
                    'location'               => 'Goromonzi residential scheme site',
                    'model'                  => '3CX Eco',
                    'serial_no'              => 'SSC-DEMO-3CX-001',
                    'effective_date'         => '2022-06-01',
                    'warranty_date'          => null,
                    'note'                   => 'Used for road grading, trenching and stand preparation.',
                    'cost'                   => 48500,
                    'maintenance_count'      => 0,
                    'maintenance_open_count' => 1,
                    'category_id'            => $equipmentCategory->id,
                    'partner_id'             => $customer->id,
                    'maintenance_team_id'    => $team->id,
                    'company_id'             => $company->id,
                ],
            );

        $stage = MaintenanceStage::query()->firstOrCreate(
            ['name' => 'Scheduled'],
            ['sort' => 10, 'done' => false],
        );

        MaintenanceRequest::withoutGlobalScopes()
            ->withTrashed()
            ->updateOrCreate(
                [
                    'name'       => 'Service backhoe before Goromonzi drainage works',
                    'company_id' => $company->id,
                ],
                [
                    'priority'              => '2',
                    'maintenance_type'      => 'preventive',
                    'requested_at'          => now()->toDateString(),
                    'scheduled_at'          => now()->addDays(7),
                    'description'           => 'Inspect hydraulics, tyres and trenching bucket before the next construction phase.',
                    'instruction_type'      => 'text',
                    'instruction_text'      => 'Record engine hours and attach the inspection checklist.',
                    'equipment_id'          => $equipment->id,
                    'stage_id'              => $stage->id,
                    'maintenance_team_id'   => $team->id,
                ],
            );
    }
}
