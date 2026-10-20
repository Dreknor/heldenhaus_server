<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Home Assistant spielt stellvertretend für ein Familienmitglied (/ha/members/{member}/…):
 * Der Familien-Token wird für diese Anfrage durch das Mitglied ersetzt, danach laufen die
 * normalen App-Controller mit allen ihren Prüfungen – als hätte das Kind selbst geklickt.
 */
class ActAsFamilyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = User::with('stats')
            ->where('family_id', $request->user()->family_id)
            ->find((int) $request->route('member'));

        abort_if($member === null, 404, 'Familienmitglied nicht gefunden.');

        // Sonst rutschen die Parameter der App-Controller um eine Stelle.
        $request->route()->forgetParameter('member');

        // Nur für diese Anfrage: danach wieder der Familien-Token (wichtig für Tests und langlebige Worker).
        $token = Auth::user();
        $resolver = $request->getUserResolver();
        Auth::setUser($member);
        $request->setUserResolver(fn () => $member);

        try {
            return $next($request);
        } finally {
            Auth::setUser($token);
            $request->setUserResolver($resolver);
        }
    }
}
