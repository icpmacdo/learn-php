<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Security headers for the browser-facing (HTML) responses: /login,
 * /dashboard* and friends. Defense-in-depth AROUND Twig autoescaping -- the
 * escaping prevents injected markup, the CSP additionally guarantees that
 * even if markup ever slipped through, no script could execute (nothing may
 * load or run scripts at all).
 *
 * Deliberately NOT applied to /api: Content-Type: application/json plus the
 * clients' own contexts govern there; these directives are about how a
 * *browser* may interpret this document.
 */
#[AsEventListener(event: 'kernel.response')]
final class SecurityHeadersListener
{
    // default-src 'none'  -> nothing loads (no scripts, images, fonts, XHR...);
    // style-src 'unsafe-inline' -> the one <style> block in base.html.twig;
    // form-action 'self'  -> login/logout forms may only post back to us;
    // frame-ancestors 'none' -> no embedding (clickjacking), CSP-era version
    //                        of X-Frame-Options below (kept for old browsers);
    // base-uri 'none'     -> no <base> tag rewriting relative URLs.
    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', self::CSP);
        // Never sniff a response into a different content type (e.g. treating
        // a user-controlled body as HTML or script).
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        // The dashboard URL structure (team ids) is nobody else's business.
        $headers->set('Referrer-Policy', 'no-referrer');
    }
}
