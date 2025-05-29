<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PhoneOrEmailService
{
    public function determineVariableType(string $variable): string
    {
        if (\filter_var($variable, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        if (\preg_match('/^[+]?\d{10,14}$/', $variable)) {
            return 'phone';
        }

        throw new BadRequestHttpException('Phone or email entered incorrectly');
    }
}
