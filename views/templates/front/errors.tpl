{extends file='page.tpl'}

{block name='page_header_container'}
    <header class="page-header">
        <h1 class="h1 page-title">
            <span>{l s='Error' mod='monei'}</span>
        </h1>
    </header>
{/block}

{block name='page_title'}
    {l s='Error' mod='monei'}
{/block}

{block name='page_content'}
    {block name='errors'}
        {include file="module:monei/views/templates/front/error.tpl"}
    {/block}
{/block}

{block name="page_footer"}
    <a href="{$urls.pages.my_account}" class="account-link">
        <i class="material-icons">&#xE5CB;</i>
        <span>{l s='Back to your account' d='Shop.Theme.Customeraccount'}</span>
    </a>
    <a href="{$urls.pages.index}" class="account-link">
        <i class="material-icons">&#xE88A;</i>
        <span>{l s='Home' d='Shop.Theme.Global'}</span>
    </a>
{/block}