<?php
/**
 * Admin on admin.example.com, WHMCS SystemURL = https://example.com.
 * A passkey add-on that builds its API URL from SystemURL makes the browser call
 * https://example.com/modules/addons/<passkey_module>/... from admin.example.com,
 * which is cross-origin and fails with "Failed to fetch".
 * This hook wraps window.fetch on the admin host only and rewrites those URLs to same-origin.
 * It lives outside the add-on (many are integrity-hashed). Undo: delete this file.
 *
 * Edit ADMIN_HOST, the domain regex, and passkey_module to match your setup.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    return "<script>(function(){if(location.hostname!==\"admin.example.com\"||!window.fetch)return;"
        . "var re=/^https?:\\/\\/(www\\.)?example\\.com(?=\\/modules\\/addons\\/passkey_module\\/)/i;"
        . "var f=window.fetch;window.fetch=function(u,o){try{if(typeof u===\"string\")u=u.replace(re,\"\");"
        . "else if(u&&u.url&&re.test(u.url))u=new Request(u.url.replace(re,\"\"),u);}catch(e){}"
        . "return f.call(this,u,o);};})();</script>";
});
