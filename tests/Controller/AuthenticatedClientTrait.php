<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Statistics\VisitorIdGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

trait AuthenticatedClientTrait
{
    private function loginTestUser(KernelBrowser $client, UserInterface $user): void
    {
        $client->disableReboot();
        $this->ensureVisitorCookie($client);
        $client->loginUser($user);
    }

    private function ensureVisitorCookie(KernelBrowser $client): void
    {
        $generator = self::getContainer()->get(VisitorIdGenerator::class);

        self::assertInstanceOf(VisitorIdGenerator::class, $generator);

        if (null !== $client->getCookieJar()->get(VisitorIdGenerator::COOKIE_NAME)) {
            return;
        }

        $request = Request::create('/');
        $response = new Response();
        $generator->attachCookie($request, $response);

        foreach ($response->headers->getCookies() as $cookie) {
            if (VisitorIdGenerator::COOKIE_NAME !== $cookie->getName()) {
                continue;
            }

            $client->getCookieJar()->set(new BrowserCookie(
                $cookie->getName(),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                false,
                $cookie->getSameSite(),
            ));

            return;
        }

        self::fail('Expected visitor cookie generation to produce a system visitor cookie.');
    }
}
