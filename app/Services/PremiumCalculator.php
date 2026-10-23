<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;


/**
 * PremiumCalculator — computes the travel insurance premium.
 *
 * Formula:
 *   premium = base_daily_rate × travel_days × age_multiplier
 *             × destination_multiplier × coverage_multiplier
 *
 * All arithmetic uses Brick\Math (arbitrary-precision decimal library)
 * to avoid floating-point rounding errors. The result is rounded to
 * exactly 2 decimal places using HALF_UP rounding (standard for money).
 *
 * Example (from the assessment):
 *   PLUS, 5 days, age 28, INTERNATIONAL, 300000
 *   = 20 × 5 × 1.00 × 1.80 × 1.25
 *   = ₹225.00
 */
class PremiumCalculator
{
    // ─── Base daily rates ─────────────────────────────────────────────────────

    private const BASE_RATES = [
        'BASIC'   => '12',
        'PLUS'    => '20',
        'PREMIUM' => '30',
    ];

    // ─── Age multipliers ──────────────────────────────────────────────────────

    // Age is calculated as of the trip_start date.
    private const AGE_MULTIPLIERS = [
        ['min' => 0,  'max' => 17,  'multiplier' => '1.10'],
        ['min' => 18, 'max' => 60,  'multiplier' => '1.00'],
        ['min' => 61, 'max' => 70,  'multiplier' => '1.50'],
        ['min' => 71, 'max' => 999, 'multiplier' => '2.00'],
    ];

    // ─── Destination multipliers ──────────────────────────────────────────────

    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC'      => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    // ─── Coverage (sum insured) multipliers ───────────────────────────────────

    private const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    // ─── Public constants for validation ─────────────────────────────────────

    public const SUPPORTED_PLANS        = ['BASIC', 'PLUS', 'PREMIUM'];
    public const SUPPORTED_DESTINATIONS = ['DOMESTIC', 'INTERNATIONAL'];
    public const SUPPORTED_SUM_INSURED  = [100000, 300000, 500000];

    /**
     * Calculate the premium for a policy request.
     *
     * @param  string  $planCode        One of BASIC, PLUS, PREMIUM
     * @param  string  $dateOfBirth     Customer's date of birth (Y-m-d)
     * @param  string  $tripStart       Trip start date (Y-m-d)
     * @param  string  $tripEnd         Trip end date (Y-m-d)
     * @param  string  $destination     One of DOMESTIC, INTERNATIONAL
     * @param  int     $sumInsured      One of 100000, 300000, 500000
     * @return array{premium: string, travel_days: int}  Premium as string (2dp) and travel days
     *
     * @throws \InvalidArgumentException if any value is unsupported
     */
    public function calculate(
        string $planCode,
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd,
        string $destination,
        int    $sumInsured
    ): array {
        // Validate inputs
        $this->assertValidPlan($planCode);
        $this->assertValidDestination($destination);
        $this->assertValidSumInsured($sumInsured);

        // Parse dates using PHP native DateTime (avoids Carbon float issues in PHP 8.4)
        $startDate = new \DateTime($tripStart);
        $endDate   = new \DateTime($tripEnd);

        // Date validation: end must not be before start
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException(
                "trip_end ($tripEnd) cannot be earlier than trip_start ($tripStart)."
            );
        }

        // +1 because both start and end days are counted
        // Use PHP native DateTime diff for reliable integer day count
        // (Carbon's diffInDays behaviour changed in PHP 8.4)
        $daysBetween = (int) (new \DateTime($tripStart))->diff(new \DateTime($tripEnd))->days;
        $travelDays  = max(1, $daysBetween + 1);

        // Calculate age on trip start date (whole years only)
        // PHP native diff is the safest approach across PHP versions
        $dob = new \DateTime($dateOfBirth);
        $age = (int) $dob->diff(new \DateTime($tripStart))->y;

        // Look up each multiplier
        $baseRate              = BigDecimal::of(self::BASE_RATES[$planCode]);
        $ageMultiplier         = BigDecimal::of($this->getAgeMultiplier($age));
        $destinationMultiplier = BigDecimal::of(self::DESTINATION_MULTIPLIERS[$destination]);
        $coverageMultiplier    = BigDecimal::of(self::COVERAGE_MULTIPLIERS[$sumInsured]);

        // Apply the formula using BigDecimal arithmetic (no float!)
        $premium = $baseRate
            ->multipliedBy($travelDays)
            ->multipliedBy($ageMultiplier)
            ->multipliedBy($destinationMultiplier)
            ->multipliedBy($coverageMultiplier)
            ->toScale(2, RoundingMode::HALF_UP); // round to 2 decimal places

        return [
            'premium'     => (string) $premium,  // "225.00" — safe to store as DECIMAL
            'travel_days' => $travelDays,
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Find the age multiplier for a given age.
     */
    private function getAgeMultiplier(int $age): string
    {
        foreach (self::AGE_MULTIPLIERS as $bracket) {
            if ($age >= $bracket['min'] && $age <= $bracket['max']) {
                return $bracket['multiplier'];
            }
        }

        // This should never happen given the 71+ bracket covers all high ages,
        // but guard against it just in case.
        throw new \InvalidArgumentException("No age multiplier found for age: $age");
    }

    private function assertValidPlan(string $planCode): void
    {
        if (!in_array($planCode, self::SUPPORTED_PLANS, strict: true)) {
            throw new \InvalidArgumentException(
                "Unsupported plan_code '$planCode'. Supported: " . implode(', ', self::SUPPORTED_PLANS)
            );
        }
    }

    private function assertValidDestination(string $destination): void
    {
        if (!in_array($destination, self::SUPPORTED_DESTINATIONS, strict: true)) {
            throw new \InvalidArgumentException(
                "Unsupported destination '$destination'. Supported: " . implode(', ', self::SUPPORTED_DESTINATIONS)
            );
        }
    }

    private function assertValidSumInsured(int $sumInsured): void
    {
        if (!in_array($sumInsured, self::SUPPORTED_SUM_INSURED, strict: true)) {
            throw new \InvalidArgumentException(
                "Unsupported sum_insured '$sumInsured'. Supported: " . implode(', ', self::SUPPORTED_SUM_INSURED)
            );
        }
    }
}
