<?php
/**
 * Admin on admin.example.com, WHMCS SystemURL = https://example.com.
 * TivroAdminPasskeys builds its API URL from SystemURL, so the browser calls
 * https://example.com/modules/addons/tivro_adminpasskeys/... from admin.example.com.
 * That is cross-origin and fails with "Failed to fetch".
 * This hook wraps window.fetch on the admin host only and rewrites those URLs to same-origin.
 * It lives outside the add-on (the ionCube build may be integrity-checked). Undo: delete this file.
 *
 * Placeholders match the README: example.com is the public host, admin.example.com is the
 * tailnet-only admin host. Edit both if your names differ. Module path is tivro_adminpasskeys.
 */
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    return "<script>(function(){if(location.hostname!==\"admin.example.com\"||!window.fetch)return;"
        . "var re=/^https?:\\/\\/(www\\.)?example\\.com(?=\\/modules\\/addons\\/tivro_adminpasskeys\\/)/i;"
        . "var f=window.fetch;window.fetch=function(u,o){try{if(typeof u===\"string\")u=u.replace(re,\"\");"
        . "else if(u&&u.url&&re.test(u.url))u=new Request(u.url.replace(re,\"\"),u);}catch(e){}"
        . "return f.call(this,u,o);};})();</script>";
});
