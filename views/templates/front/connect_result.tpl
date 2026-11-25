{*
 * The page of the Connect with MONEI callback, a whole HTML document.
 *
 * Colors, radii and shadows come from the MONEI brand tokens (assets.monei.com), as
 * in the WooCommerce and Magento plugins. Nothing loads from another host: the logo
 * is the module's own file and the font is the system font.
 *}
<!DOCTYPE html>
<html lang="{$monei_connect_result.lang|escape:'html':'UTF-8'}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{$monei_connect_result.title|escape:'html':'UTF-8'} · MONEI</title>
    <style>
{literal}
        :root {
            --teal: #00c1b1;
            --teal-dark: #009e91;
            --teal-tint: rgba(0, 193, 177, .1);
            --red: #ff4a5c;
            --red-tint: rgba(255, 74, 92, .1);
            --ink-900: #0b1324;
            --ink-700: #2c3651;
            --ink-500: #6b7694;
            --canvas: #f7f9fc;
            --line: #e9ecf4;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: var(--canvas);
            color: var(--ink-700);
            font: 15px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 440px;
            padding: 40px 32px 32px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(11, 19, 36, .06);
            text-align: center;
        }
        .logo { display: block; height: 24px; width: auto; margin: 0 auto 32px; }
        .status {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 999px;
            background: var(--teal-tint);
            color: var(--teal-dark);
        }
        .status--error { background: var(--red-tint); color: var(--red); }
        h1 { margin: 0 0 20px; color: var(--ink-900); font-size: 22px; line-height: 1.3; font-weight: 600; }
        .lines { margin: 0 0 24px; padding: 0; list-style: none; text-align: left; }
        .lines li {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: var(--canvas);
        }
        .lines li + li { margin-top: 8px; }
        .lines svg { flex: none; margin-top: 3px; }
        .line--ok svg { color: var(--teal-dark); }
        .line--problem svg { color: var(--ink-500); }
        .button {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 999px;
            background: var(--teal);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
        .button:hover, .button:focus-visible { background: var(--teal-dark); }
        .button:focus-visible { outline: 2px solid var(--ink-900); outline-offset: 2px; }
        .hint { margin: 16px 0 0; color: var(--ink-500); font-size: 13px; }
{/literal}
    </style>
</head>
<body>
    <main class="card" role="status">
        <img class="logo" src="{$monei_connect_result.logoUrl|escape:'html':'UTF-8'}" alt="MONEI" width="110" height="24">
        <div class="status{if $monei_connect_result.state !== 'connected'} status--error{/if}" aria-hidden="true">
            {if $monei_connect_result.state === 'connected'}
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            {else}
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.01"/><circle cx="12" cy="12" r="9"/></svg>
            {/if}
        </div>
        <h1>{$monei_connect_result.title|escape:'html':'UTF-8'}</h1>
        {if $monei_connect_result.lines}
            <ul class="lines">
                {foreach $monei_connect_result.lines as $line}
                    <li class="{if $line.ok}line--ok{else}line--problem{/if}">
                        {if $line.ok}
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        {else}
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/></svg>
                        {/if}
                        <span>{$line.text|escape:'html':'UTF-8'}</span>
                    </li>
                {/foreach}
            </ul>
        {/if}
        {* A refused link names no shop, so there is no settings page to link to. *}
        {if $monei_connect_result.settingsUrl}
            <a class="button" href="{$monei_connect_result.settingsUrl|escape:'html':'UTF-8'}">{l s='Go to MONEI settings' mod='monei'}</a>
        {/if}
        {if $monei_connect_result.state !== 'refused'}
            <p class="hint">{l s='You can also close this tab and reload the settings page.' mod='monei'}</p>
        {/if}
    </main>
</body>
</html>
