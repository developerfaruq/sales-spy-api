<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Plan;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $plans = [
            [
                'slug'          => 'free',
                'name'          => 'Free',
                'monthly_price' => 0,
                'yearly_price'  => 0,
                'monthly_quota' => 50,
                'features'      => [
                    '50 leads per month',
                    'Basic website filtering',
                    'Email support',
                ],
                'is_active'  => true,
                'sort_order' => 0,
            ],
            [
                'slug'          => 'basic',
                'name'          => 'Basic',
                'monthly_price' => 2000, // $20.00
                'yearly_price'  => 19200, // $1,104.00 (20% off)
                'monthly_quota' => 500,
                'features'      => [
                    '500 leads per month',
                    'Basic filtering options',
                    'Email and phone support',
                    'Weekly database updates',
                    'Website & e-commerce leads',
                ],
                'is_active'  => true,
                'sort_order' => 1,
            ],
            [
                'slug'          => 'pro',
                'name'          => 'Pro',
                'monthly_price' => 5000, // $50.00
                'yearly_price'  => 48000, // $480.00
                'monthly_quota' => 2000,
                'features'      => [
                    '2,000 leads per month',
                    'Advanced filtering options',
                    'Priority support',
                    'Daily database updates',
                    'CRM integration',
                    'Email sequence automation',
                    'Full contact details',
                ],
                'is_active'  => true,
                'sort_order' => 2,
            ],
            [
                'slug'          => 'enterprise',
                'name'          => 'Enterprise',
                'monthly_price' => 0, // custom pricing
                'yearly_price'  => 0,
                'monthly_quota' => -1, // unlimited
                'features'      => [
                    'Unlimited leads per month',
                    'Custom filtering & API access',
                    'Dedicated account manager',
                    'Real-time database updates',
                    'Onboarding & training',
                    'Custom integrations',
                    'SLA guarantee',
                ],
                'is_active'  => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }

        $this->command->info('Plans seeded: free, basic, pro, enterprise');
    }
}
