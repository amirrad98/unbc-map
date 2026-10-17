<?php
declare(strict_types=1);

function atlasClerkScripts(array $config, bool $login): string
{
    $options = json_encode([
        'base' => $config['base'], 'login' => $login,
        'origin' => $config['origin'],
        'returnTo' => atlasReturnTo(is_string($_GET['return_to'] ?? null) ? $_GET['return_to'] : $config['base'], $config['base']),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $issuer = htmlspecialchars($config['issuer'], ENT_QUOTES);
    $pk = htmlspecialchars($config['publishableKey'], ENT_QUOTES);
    return <<<HTML
<script defer crossorigin="anonymous" src="$issuer/npm/@clerk/ui@1/dist/ui.browser.js"></script>
<script defer crossorigin="anonymous" data-clerk-publishable-key="$pk" src="$issuer/npm/@clerk/clerk-js@6/dist/clerk.browser.js"></script>
<script>
window.addEventListener('load', async () => {
  const options = $options;
  const signIn = options.base + 'sign-in';
  const original = location.pathname + location.search + location.hash;
  if (options.login && location.pathname !== signIn) {
    location.replace(signIn + '?return_to=' + encodeURIComponent(original));
    return;
  }
  function localReturn(value) {
    try {
      const url = new URL(value || options.base, location.origin);
      if (url.origin !== location.origin || !url.pathname.startsWith(options.base) || url.pathname.startsWith(signIn)) return options.base;
      return url.pathname + url.search + url.hash;
    } catch { return options.base; }
  }
  const returnTo = localReturn(options.returnTo);
  const message = document.getElementById('auth-status');
  try {
    if (!window.Clerk) throw new Error('Sign-in service unavailable');
    await Clerk.load({ui: {ClerkUI: window.__internal_ClerkUICtor},
      signInUrl: location.origin + signIn,
      signInFallbackRedirectUrl: location.origin + returnTo,
      signUpFallbackRedirectUrl: location.origin + returnTo,
      afterSignOutUrl: location.origin + signIn});
    if (options.login) {
      let opening = false;
      const openAtlas = async () => {
        if (!Clerk.session || opening) return;
        opening = true;
        try {
          await Clerk.session.getToken({skipCache: true});
          const response = await fetch(options.base + 'session', {credentials: 'same-origin', cache: 'no-store'});
          if (!response.ok) throw new Error('Session not accepted');
          location.replace(returnTo);
        } catch {
          opening = false;
          message.textContent = 'Your session could not be verified. Please sign out and try again.';
          document.getElementById('auth-sign-out').hidden = false;
        }
      };
      document.getElementById('auth-sign-out').onclick = () => Clerk.signOut({redirectUrl: signIn});
      Clerk.addListener(({session}) => {if (session) openAtlas();});
      if (Clerk.session) { await openAtlas(); }
      else {
        message.textContent = 'Sign in with your invited email address. We’ll send you a login code.';
        Clerk.mountSignIn(document.getElementById('clerk-sign-in'), {
          routing: 'hash', forceRedirectUrl: location.href,
          appearance: {variables: {colorPrimary: '#00573c'}}
        });
      }
    } else {
      const controls = document.createElement('aside');
      controls.setAttribute('aria-label', 'Your account');
      controls.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;background:white;padding:10px 14px;border:1px solid #ddd;border-radius:8px;box-shadow:0 2px 12px #0002;display:flex;gap:12px;align-items:center;font:14px Arial,sans-serif';
      const avatar = document.createElement('span');
      const out = document.createElement('button');
      out.textContent = 'Sign out';
      out.style.cssText = 'cursor:pointer;background:#00573c;color:white;border:0;padding:8px 12px;border-radius:4px';
      out.onclick = () => Clerk.signOut({redirectUrl: signIn});
      controls.append(avatar, out); document.body.appendChild(controls);
      Clerk.mountUserButton(avatar);
      Clerk.addListener(({session}) => {if (!session) location.replace(signIn + '?return_to=' + encodeURIComponent(original));});
    }
  } catch {
    if (message) message.textContent = 'Sign-in is temporarily unavailable. Please contact the NHHR team.';
  }
});
</script>
HTML;
}

function atlasLoginPage(array $config): void
{
    header('Content-Type: text/html; charset=utf-8');
    $scripts = atlasClerkScripts($config, true);
    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Sign in · NHHR Atlas</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f6f5f1;color:#252b29;font:16px/1.6 Arial,Helvetica,sans-serif}header{background:#00573c;border-bottom:6px solid #fdb913;color:#fff;padding:24px max(24px,calc((100vw - 1000px)/2))}header strong{font-size:22px}header p{margin:0;font-size:14px}main{max-width:560px;margin:64px auto;padding:0 24px}h1{font-size:36px;line-height:1.15;margin-bottom:16px}#clerk-sign-in{margin-top:24px}small{display:block;margin-top:28px;color:#59645e}button{cursor:pointer;padding:10px 16px}a{color:#00573c}</style>
$scripts</head><body><header><strong>NHHR Atlas</strong><p>Northern Hub for Health Research · UNBC</p></header>
<main><h1>Sign in to the Atlas</h1><p>This website is available to invited NHHR users.</p><p id="auth-status" role="status">Connecting to secure sign-in…</p><div id="clerk-sign-in"></div><button id="auth-sign-out" hidden>Sign out</button><noscript>JavaScript is required to sign in.</noscript><small>Access is by invitation. Contact the NHHR team if you need an account.</small></main></body></html>
HTML;
}
