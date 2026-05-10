<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key'         => 'crypto_wallet_address',
                'value'       => 'THj2BEzqZLLmmHThDgQpjQJzDDowmt12KX',
                'type'        => 'string',
                'description' => 'TRC20 USDT wallet address for receiving payments',
            ],
            [
                'key'         => 'crypto_network',
                'value'       => 'TRC20',
                'type'        => 'string',
                'description' => 'Blockchain network for crypto payments',
            ],
            [
                'key'         => 'crypto_currency',
                'value'       => 'USDT',
                'type'        => 'string',
                'description' => 'Accepted cryptocurrency',
            ],
            [
                'key'         => 'payment_order_expiry_hours',
                'value'       => '24',
                'type'        => 'integer',
                'description' => 'Hours before a pending payment order expires',
            ],
            [
                'key'         => 'site_name',
                'value'       => 'Sales-Spy',
                'type'        => 'string',
                'description' => 'Application name used in emails and notifications',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        $this->command->info('Settings seeded successfully');
    }
}
