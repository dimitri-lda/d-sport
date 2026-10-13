<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Application\Identity\PasswordPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    public function testValidPasswordPasses(): void
    {
        self::assertSame([], PasswordPolicy::violations('Sport-E!2026'));
        // Boundary: exactly 10 characters with all classes present.
        self::assertSame([], PasswordPolicy::violations('Aa1!bcdefg'));
    }

    public function testShortAndLongPasswordsRejected(): void
    {
        self::assertContains(
            'The password must be at least 10 characters long.',
            PasswordPolicy::violations('Aa1!bcde'),
        );
        self::assertContains(
            'The password must be at most 255 characters long.',
            PasswordPolicy::violations(str_repeat('Aa1!', 70)),
        );
    }

    public function testEachCharacterClassIsRequired(): void
    {
        self::assertContains(
            'The password must contain at least one uppercase letter.',
            PasswordPolicy::violations('aa1!bcdefgh'),
        );
        self::assertContains(
            'The password must contain at least one lowercase letter.',
            PasswordPolicy::violations('AA1!BCDEFGH'),
        );
        self::assertContains(
            'The password must contain at least one digit.',
            PasswordPolicy::violations('AaB!cdefghi'),
        );
        self::assertContains(
            'The password must contain at least one symbol.',
            PasswordPolicy::violations('Aa1Bcdefgh2'),
        );
    }

    public function testEmptyPasswordReportsMultipleViolations(): void
    {
        self::assertGreaterThan(3, \count(PasswordPolicy::violations('')));
    }
}
