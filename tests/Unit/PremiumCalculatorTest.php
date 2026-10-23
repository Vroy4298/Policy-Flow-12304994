<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use PHPUnit\Framework\TestCase;

/**
 * PremiumCalculatorTest — unit tests for the premium calculation logic.
 *
 * These tests do NOT require a database because PremiumCalculator
 * is a pure PHP class with no dependencies on Eloquent or the database.
 *
 * Run with: php artisan test --filter PremiumCalculatorTest
 */
class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PremiumCalculator();
    }

    // ─── Reference example from the assessment ────────────────────────────────

    /**
     * THE reference calculation from the assessment document.
     * This test MUST pass to satisfy the specification.
     *
     * PLUS × 5 days × age 28 × INTERNATIONAL × 300000
     * = 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00
     */
    public function test_reference_example_from_assessment_225_rupees(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'PLUS',
            dateOfBirth: '1998-04-10',   // age 28 on 2026-11-10
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-14',   // 5 days inclusive
            destination: 'INTERNATIONAL',
            sumInsured:  300000
        );

        $this->assertSame('225.00', $result['premium']);
        $this->assertSame(5, $result['travel_days']);
    }

    // ─── Age boundary tests ───────────────────────────────────────────────────

    public function test_age_bracket_0_to_17_uses_110_multiplier(): void
    {
        // Age 15 on trip_start → multiplier 1.10
        // BASIC × 1 day × 1.10 × DOMESTIC × 100000
        // = 12 × 1 × 1.10 × 1.00 × 1.00 = 13.20
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '2011-03-01', // age 15 on 2026-11-10
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10', // 1 day
            destination: 'DOMESTIC',
            sumInsured:  100000
        );

        $this->assertSame('13.20', $result['premium']);
    }

    public function test_age_bracket_18_to_60_uses_100_multiplier(): void
    {
        // Age 30 → multiplier 1.00
        // BASIC × 1 × 1.00 × DOMESTIC × 100000 = 12.00
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01', // age 30
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );

        $this->assertSame('12.00', $result['premium']);
    }

    public function test_age_bracket_61_to_70_uses_150_multiplier(): void
    {
        // Age 65 → multiplier 1.50
        // BASIC × 1 × 1.50 × DOMESTIC × 100000 = 18.00
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1961-06-01', // age 65 on 2026-11-10
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );

        $this->assertSame('18.00', $result['premium']);
    }

    public function test_age_bracket_71_plus_uses_200_multiplier(): void
    {
        // Age 76 → multiplier 2.00
        // BASIC × 1 × 2.00 × DOMESTIC × 100000 = 24.00
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1950-01-01', // age 76 on 2026-11-10
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );

        $this->assertSame('24.00', $result['premium']);
    }

    // ─── Plan base rate tests ─────────────────────────────────────────────────

    public function test_basic_plan_base_rate_is_12_per_day(): void
    {
        // BASIC × 1 day × 1.00 × DOMESTIC × 100000 = 12.00
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
        $this->assertSame('12.00', $result['premium']);
    }

    public function test_plus_plan_base_rate_is_20_per_day(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'PLUS',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
        $this->assertSame('20.00', $result['premium']);
    }

    public function test_premium_plan_base_rate_is_30_per_day(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'PREMIUM',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
        $this->assertSame('30.00', $result['premium']);
    }

    // ─── Travel day calculation ───────────────────────────────────────────────

    public function test_travel_days_are_inclusive(): void
    {
        // trip_start = trip_end → 1 day
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',  // same day
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
        $this->assertSame(1, $result['travel_days']);
    }

    public function test_five_day_trip_is_calculated_correctly(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-14',  // 5 days inclusive
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
        $this->assertSame(5, $result['travel_days']);
    }

    // ─── Validation error tests ───────────────────────────────────────────────

    public function test_unsupported_plan_code_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported plan_code/');

        $this->calculator->calculate(
            planCode:    'GOLD',  // not a valid plan
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
    }

    public function test_unsupported_sum_insured_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported sum_insured/');

        $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  200000  // not supported
        );
    }

    public function test_trip_end_before_start_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be earlier than/');

        $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-14',
            tripEnd:     '2026-11-10', // before trip_start
            destination: 'DOMESTIC',
            sumInsured:  100000
        );
    }

    public function test_unsupported_destination_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported destination/');

        $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'REGIONAL',  // not supported
            sumInsured:  100000
        );
    }

    // ─── Destination multiplier tests ─────────────────────────────────────────

    public function test_international_destination_applies_180_multiplier(): void
    {
        // BASIC × 1 × 1.00 × INTERNATIONAL(1.80) × 100000 = 21.60
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'INTERNATIONAL',
            sumInsured:  100000
        );
        $this->assertSame('21.60', $result['premium']);
    }

    // ─── Coverage multiplier tests ────────────────────────────────────────────

    public function test_500000_sum_insured_applies_150_multiplier(): void
    {
        // BASIC × 1 × 1.00 × DOMESTIC × 500000(1.50) = 18.00
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: '1996-01-01',
            tripStart:   '2026-11-10',
            tripEnd:     '2026-11-10',
            destination: 'DOMESTIC',
            sumInsured:  500000
        );
        $this->assertSame('18.00', $result['premium']);
    }
}
