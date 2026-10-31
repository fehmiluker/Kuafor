<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus as S;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $this->assertTrue(S::Pending->canTransitionTo(S::Confirmed));
        $this->assertTrue(S::Pending->canTransitionTo(S::AlternativeProposed));
        $this->assertTrue(S::AlternativeProposed->canTransitionTo(S::Confirmed));
        $this->assertTrue(S::Confirmed->canTransitionTo(S::Completed));
        $this->assertTrue(S::Confirmed->canTransitionTo(S::NoShow));
    }

    public function test_forbidden_transitions(): void
    {
        $this->assertFalse(S::Completed->canTransitionTo(S::Cancelled));
        $this->assertFalse(S::Cancelled->canTransitionTo(S::Confirmed));
        $this->assertFalse(S::Pending->canTransitionTo(S::Completed));
        $this->assertFalse(S::Expired->canTransitionTo(S::Confirmed));
    }

    public function test_terminal_states(): void
    {
        foreach ([S::Cancelled, S::Completed, S::NoShow, S::Expired, S::Rejected] as $s) {
            $this->assertTrue($s->isTerminal(), $s->value);
        }
        $this->assertFalse(S::Confirmed->isTerminal());
    }
}
