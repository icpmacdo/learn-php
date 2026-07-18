<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The browser login page. GET renders the form; POST to the same path is
 * intercepted by the form_login authenticator on the web firewall before any
 * controller runs (this action only ever executes for GET, or for a POST
 * that somehow bypassed the firewall -- which would just re-render the form).
 *
 * /logout has no controller at all: the firewall's logout listener handles
 * it (see security.yaml).
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'lastUsername' => $authenticationUtils->getLastUsername(),
        ]);
    }
}
