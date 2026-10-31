<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_normalizes_turkish_formats_to_e164(): void
    {
        foreach (['0532 123 45 67', '5321234567', '+90 (532) 123-45-67', '905321234567'] as $in) {
            $this->assertSame('+905321234567', PhoneNumber::normalize($in), $in);
        }
    }

    public function test_rejects_invalid(): void
    {
        $this->assertNull(PhoneNumber::normalize('12345'));
        $this->assertNull(PhoneNumber::normalize('abc'));
        $this->assertNull(PhoneNumber::normalize(''));
    }

    public function test_mask_and_waid(): void
    {
        $this->assertSame('+90532*****67', PhoneNumber::mask('+905321234567'));
        $this->assertSame('905321234567', PhoneNumber::toWaId('+905321234567'));
    }
}
