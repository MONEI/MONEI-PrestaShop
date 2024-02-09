

{if $errors}
    <div>
        <h3>{l s='An error occurred' mod='monei'}:</h3>
        <ul class="alert alert-danger">
            {foreach from=$errors item='error'}
                <li>{$error|escape:'htmlall':'UTF-8'}.</li>
            {/foreach}
        </ul>
    </div>
{/if}