<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class SsoAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Override]
    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    #[Override]
    public function authenticate(Request $request): Passport
    {
        // Get token from header
        $jwtToken = $request->headers->get('Authorization');

        if (false === str_starts_with((string) $jwtToken, 'Bearer ')) {
            throw new AuthenticationException('Invalid token');
        }

        $jwtToken = str_replace('Bearer ', '', (string) $jwtToken);

        // Decode the token
        $parts = explode('.', $jwtToken);

        if (count($parts) !== 3) {
            throw new AuthenticationException('Invalid token');
        }

        json_decode(base64_decode($parts[0]), true);

        $publicKey = file_get_contents($this->parameterBag->get('jwt_public_key'));

        // Validate token
        try {
            $decodedToken = JWT::decode($jwtToken, new Key($publicKey, 'RS256'));
        } catch (Exception $exception) {
            throw new AuthenticationException($exception->getMessage());
        }

        return new SelfValidatingPassport(
            new UserBadge($decodedToken->email, function (string $globalUuid) {
                $user = $this->userRepository->findOneBy(['email' => $globalUuid]);

                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException(sprintf('User with global uuid "%s" not found.', $globalUuid));
                }

                return $user;
            })
        );
    }

    #[Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = [
                'error' => strtr($exception->getMessageKey(), $exception->getMessageData()),
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    public function getUserBadgeFrom(#[SensitiveParameter] string $globalUuid): UserBadge
    {
        $user = $this->userRepository->findOneBy(['email' => $globalUuid]);

        if ($user === null) {
            throw new AuthenticationException('User not found');
        }

        return new UserBadge($user->getUserIdentifier());
    }
}
