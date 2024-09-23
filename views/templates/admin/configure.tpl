<div class="container-fluid">
    <!-- Normal UI -->
    <div class="row">
        <div class="col-md-12">
            <div class="tabbable" id="tabs-270581">
                <ul class="nav nav-tabs">
                    <li class="{if !(isset($pbtab))}active{/if}">
                        <a href="#panel-info" data-toggle="tab"><i class="icon icon-info-circle"></i>
                            {l s='Information' mod='monei'}</a>
                    </li>
                    <li>
                        <a href="#panel-conf-1" data-toggle="tab"><i class="icon icon-cogs"></i>
                            {l s='Settings' mod='monei'}</a>
                    </li>
                    <li>
                        <a href="#panel-conf-2" data-toggle="tab"><i class="icon icon-money"></i>
                            {l s='Payment methods' mod='monei'}</a>
                    </li>
                    <li>
                        <a href="#panel-conf-3" data-toggle="tab"><i class="icon icon-shopping-cart"></i>
                            {l s='Payment Status' mod='monei'}</a>
                    </li>
                    <li>
                        <a href="#panel-conf-4" data-toggle="tab"><i class="icon icon-paint-brush"></i>
                            {l s='Component Style' mod='monei'}</a>
                    </li>
                    <li>
                        <a href="#panel-docs" data-toggle="tab"><i class="icon icon-book"></i>
                            {l s='Documentation' mod='monei'}</a>
                    </li>
                </ul>
                <!-- TABS -->
                <div class="tab-content">
                    <!-- INFORMATION AND CONTROLS -->
                    <div class="tab-pane {if !(isset($pbtab))}active{/if}" id="panel-info">
                        <div class="panel monei-back">
                            <h3>{$display_name|escape:'html':'UTF-8'} {$module_version|escape:'html':'UTF-8'}</h3>
                            <div class="row">
                                <div class="col-md-6">
                                    <p>
                                    <p style="align:center;">
                                        <img style="width:120px" src="https://assets.monei.com/images/logo.svg"
                                            alt="{$display_name|escape:'html':'UTF-8'}">
                                    </p>
                                    <strong>{l s='Grow your business faster with the advanced payment platform' mod='monei'}</strong><br />
                                    </p>
                                </div>
                                <div class="col-md-6">

                                </div>
                            </div>

                            <div class="panel-footer">
                                <a class="btn btn-default"
                                    href="https://support.monei.com/hc/en-us/requests/new?ticket_form_id=360000322338"
                                    target="_blank">
                                    <i class="icon icon-envelope"></i> {l s='Contact support' mod='monei'}
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- BASIC CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-1">
                        <div class="panel">
                            {$helper_form_1} {* HelperForm, no escaping *}
                        </div>
                    </div>

                    <!-- PAYMENT METHODS CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-2">
                        <div class="panel">
                            <div class="alert alert-warning" role="alert">
                                <p>
                                    {l s='Remember to activate your payment methods at your MONEI dashboard first.' mod='monei'}
                                </p>
                            </div>
                            {$helper_form_2} {* HelperForm, no escaping *}
                        </div>
                    </div>

                    <!-- STATUS CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-3">
                        <div class="panel">
                            {$helper_form_3} {* HelperForm, no escaping *}
                        </div>
                    </div>

                    <!-- STYLES CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-4">
                        <div class="panel">
                            {$helper_form_4} {* HelperForm, no escaping *}
                        </div>
                    </div>

                    <!-- DOCUMENTATION -->
                    <div class="tab-pane" id="panel-docs">
                        <div class="panel monei-back">
                            <h3>{l s='Documentation' mod='monei'}</h3>
                            <p>
                            <p>
                                {l s='Read the documentation of this module:' mod='monei'} <a
                                    href="https://docs.monei.com/docs/e-commerce/prestashop/"
                                    target="_blank">{l s='HERE' mod='monei'}</a><br />
                            </p>
                        </div>
                    </div>
                    <!-- END TABS -->
                </div>
            </div>
        </div>
    </div>
</div>