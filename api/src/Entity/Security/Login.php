<?php

declare(strict_types=1);

namespace App\Entity\Security;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Controller\Security\LoginController;

use App\Controller\Security\VerifyController;
use ArrayObject;

#[ApiResource]
#[Post(
    uriTemplate: '/login/verify',
    controller: VerifyController::class,
    openapi: new Model\Operation(
        summary: 'Роут для прохождения верификации пользователя',
        description: 'Верификация',
        requestBody: new Model\RequestBody(
            description: 'Verification body',
            content: new ArrayObject([
                    'application/json' => [
                            'schema' => [
                                    'properties' => [
                                            'login' => ['type' => 'string'],
                                            'code' => ['type' => 'string'],
                                    ],
                                    'type' => 'object',
                            ],
                    ],
            ],
            )
        ),
    ),
    name: 'verify'
)]

final class Login
{
}
