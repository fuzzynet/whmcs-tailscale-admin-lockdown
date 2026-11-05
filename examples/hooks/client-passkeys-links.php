<?php
/**
 * Make Tivro Passkeys (index.php?m=tivro_passkeys) easy to find in the client area.
 * - Adds "Passkeys" to the Account dropdown. In WHMCS 8 the Account menu lives in the
 *   SECONDARY navbar, so hooks that look for 'Account' in the primary navbar never land.
 * - Adds a "Passkeys" panel with a button on the user Security page.
 * Does not touch the add-on's own files. Undo: delete this file.
 *
 * Hostnames in this repo are placeholders; the module name matches the live client add-on.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

add_hook('ClientAreaSecondaryNavbar', 50, function ($nav) {
    try {
        if (empty($_SESSION['uid']) && empty($_SESSION['login_auth_tk'])) { return; }
        $acct = $nav->getChild('Account');
        if (!$acct || $acct->getChild('Custom Passkeys')) { return; }
        $acct->addChild('Custom Passkeys', [
            'label' => 'Passkeys',
            'uri' => 'index.php?m=tivro_passkeys',
            'order' => 45,
        ]);
    } catch (\Throwable $e) {}
});

add_hook('ClientAreaFooterOutput', 50, function ($vars) {
    try {
        $tpl = isset($vars['templatefile']) ? $vars['templatefile'] : '';
        if (!in_array($tpl, ['user-security', 'clientareasecurity'], true)) { return ''; }
        return <<<'HTML'
<script>
(function(){function go(){if(document.getElementById('custom-passkeys-panel'))return;
var anchor=document.querySelector('.main-content .panel')||document.querySelector('.main-content');if(!anchor)return;
var d=document.createElement('div');d.id='custom-passkeys-panel';d.className='panel panel-default';
d.innerHTML='<div class="panel-heading"><h3 class="panel-title">Passkeys</h3></div><div class="panel-body"><p>Sign in with your fingerprint, face, or a password manager instead of a password. Add or remove passkeys for your account here.</p><a href="index.php?m=tivro_passkeys" class="btn btn-lg btn-primary">Manage Passkeys</a></div>';
if(anchor.classList.contains('panel')){anchor.parentNode.insertBefore(d,anchor);}else{anchor.insertBefore(d,anchor.firstChild);}}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',go);}else{go();}})();
</script>
HTML;
    } catch (\Throwable $e) { return ''; }
});
