<?php

namespace Tests\Feature;

use App\Services\ContactNormalizer;
use Tests\TestCase;

class ContactNormalizerTest extends TestCase
{
    public function test_phone_normalizes_local_number_to_country_code(): void
    {
        $normalizer = new ContactNormalizer;

        $this->assertSame('971501234567', $normalizer->phone('0501234567', 'AE'));
        $this->assertSame('971501234567', $normalizer->phone('+971501234567', 'AE'));
        $this->assertSame('971501234567', $normalizer->phone('+971 50 123 4567', 'AE'));
        $this->assertSame('971501234567', $normalizer->phone('00971501234567', 'AE'));
    }

    public function test_phone_keeps_explicit_international_form(): void
    {
        $normalizer = new ContactNormalizer;

        $this->assertSame('15551234567', $normalizer->phone('+1 (555) 123-4567'));
    }

    public function test_phone_ignores_garbage(): void
    {
        $normalizer = new ContactNormalizer;

        $this->assertNull($normalizer->phone('y/123 asd', 'AE'));
        $this->assertNull($normalizer->phone('', 'AE'));
        $this->assertNull($normalizer->phone('abc', 'AE'));
        $this->assertNull($normalizer->phone('123', 'AE'));
    }

    public function test_same_phone_normalizes_different_formats(): void
    {
        $normalizer = new ContactNormalizer;

        $this->assertTrue($normalizer->samePhone('0501234567', '+971501234567', 'AE'));
        $this->assertTrue($normalizer->samePhone('+971 50 1234 567', '00971501234567', 'AE'));
        $this->assertFalse($normalizer->samePhone('0501234567', '+971509999999', 'AE'));
        $this->assertFalse($normalizer->samePhone('0501234567', '+15551234567', 'AE'));
    }

    public function test_email_is_case_and_space_insensitive(): void
    {
        $normalizer = new ContactNormalizer;

        $this->assertTrue($normalizer->sameEmail(' Sara.Ahmed@Example.COM ', 'sara.ahmed@example.com'));
        $this->assertFalse($normalizer->sameEmail('sara@example.com', 'sara@example.net'));
        $this->assertFalse($normalizer->sameEmail('sara@example.com', ''));
    }
}