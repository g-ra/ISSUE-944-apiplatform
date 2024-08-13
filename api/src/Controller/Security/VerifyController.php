<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Entity\Verification;
use App\Repository\UserRepository;
use App\Service\Security\AuthService;
use App\Service\Security\PhoneOrEmailService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use DateTimeImmutable;
use JsonException;

#[AsController]
final class VerifyController extends AbstractController
{
    public function __construct(
        private readonly PhoneOrEmailService $phoneOrEmailService,
        private readonly JWTTokenManagerInterface $tokenManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OptimisticLockException
     * @throws JsonException
     * @throws ORMException
     */
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $user = null;
        $data = \json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $login = $data['login'] ?? null;
        $code = $data['code'] ?? null;

        $user = $this->userRepository->findUserByEmailOrPhone($login);

        if (!$user instanceof \App\Entity\User) {
            return $this->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $this->tokenManager->create($user);

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 3600);
        $this->refreshTokenManager->save($refreshToken);

        return $this->json([
                'token' => $token,
                'refreshToken' => $refreshToken->getRefreshToken(),
        ]);
    }
}
