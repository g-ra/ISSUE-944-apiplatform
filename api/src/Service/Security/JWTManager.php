<?php

namespace App\Service\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\HeaderAwareJWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTEncodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class JWTManager extends \Lexik\Bundle\JWTAuthenticationBundle\Services\JWTManager
{

    /**
     * @inheritDoc
     */
    public function create(UserInterface|User $user): string
    {
        $payload = ['email'=>$user->getEmail(), 'name'=>$user->getName()];
        $this->addUserIdentityToPayload($user, $payload);

        return $this->generateJwtStringAndDispatchEvents($user, $payload);
    }
    /**
     * @inheritDoc
     */
    public function decode(TokenInterface $token): bool|array
    {
        if (!($payload = $this->jwtEncoder->decode($token->getCredentials()))) {
            return false;
        }

        $event = new JWTDecodedEvent($payload);
        $this->dispatcher->dispatch($event, Events::JWT_DECODED);

        if (!$event->isValid()) {
            return false;
        }

        return $event->getPayload();
    }

    private function generateJwtStringAndDispatchEvents(UserInterface $user, array $payload): string
    {
        $jwtCreatedEvent = new JWTCreatedEvent($payload, $user);
        $this->dispatcher->dispatch($jwtCreatedEvent, Events::JWT_CREATED);

        if ($this->jwtEncoder instanceof HeaderAwareJWTEncoderInterface) {
            $jwtString = $this->jwtEncoder->encode($jwtCreatedEvent->getData(), $jwtCreatedEvent->getHeader());
        } else {
            $jwtString = $this->jwtEncoder->encode($jwtCreatedEvent->getData());
        }

        $jwtEncodedEvent = new JWTEncodedEvent($jwtString);

        $this->dispatcher->dispatch($jwtEncodedEvent, Events::JWT_ENCODED);
        return $jwtString;
    }
}
