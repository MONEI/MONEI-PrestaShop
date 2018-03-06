<div class="row">
    <div class="col-lg-12">
        <br>
        <form id="configuration_form" class="form-horizontal moneiConfigForm"
              action="index.php?controller=AdminModules&amp;configure=moneipaymentplatform&amp;token={{$token}}"
              method="post" enctype="multipart/form-data" novalidate="">
            <input type="hidden" name="submitmoneipaymentplatform" value="1">

            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-cogs"></i> {{$heading}}
                </div>
                <div class="alert alert-info">
                    <p><b>MONEI Payment Gateway</b> is the easiest way to accept payments from your customers.</p>
                    <p>
                        To use this payment method you need to be registered in
                        <a href="https://monei.net/" target="_blank">MONEI Dashboard</a>
                    </p>
                </div>
                <div class="form-wrapper">
                    <div class="form-group {if $isSubmitted && $defaultValues['monei_token']==null} has-error{/if}">
                        <label class="control-label col-lg-3 required" for="monei_token">
                            Secret Token
                        </label>
                        <div class="col-lg-9">
                            <input type="text" name="monei_token" id="monei_token" required="required">
                            <p class="help-block">
                                Secret token generated for your sub account in <a href="https://monei.net/"
                                                                                  target="_blank">MONEI Dashboard</a>
                            </p>
                        </div>
                    </div>
                    <div class="form-group {if $isSubmitted && $defaultValues['monei_brands']==null} has-error{/if}">
                        <label class="control-label col-lg-3 required" for="monei_brands">
                            Payment methods
                        </label>
                        <div class="col-lg-9">
                            <select multiple="multiple" class="bootstrap" name="monei_brands[]"
                                    id="monei_brands">
                                <option value="AMEX">American Express</option>
                                <option value="JCB">JCB</option>
                                <option value="MAESTRO">Maestro</option>
                                <option value="MASTER">MasterCard</option>
                                <option value="MASTERDEBIT">MasterCard Debit</option>
                                <option value="VISA">Visa</option>
                                <option value="VISADEBIT">Visa Debit</option>
                                <option value="VISAELECTRON">Visa Electron</option>
                                <option value="PAYPAL">PayPal</option>
                                <option value="BITCOIN">Bitcoin</option>
                                <option value="ALIPAY">Alipay</option>
                                <option value="DIRECTDEBIT_SEPA">SEPA Direct Debit</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="monei_descriptor">
                            Descriptor
                        </label>
                        <div class="col-lg-9">
                            <input type="text" name="monei_descriptor" id="monei_descriptor">
                            <p class="help-block">
                                Descriptor that will be shown in customer's bank statement
                            </p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="monei_submit_text">
                            Submit text
                        </label>
                        <div class="col-lg-9">
                            <input type="text" name="monei_submit_text" id="monei_submit_text">
                            <p class="help-block">
                                Submit button text, &#123;amount&#125; will be replaced with amount value with currency. Default:
                                Pay now
                            </p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="monei_show_cardholder">
                            Show carholder field
                        </label>
                        <div class="col-lg-9">
                            <input type="checkbox" name="monei_show_cardholder" id="monei_show_cardholder" {if $defaultValues['monei_show_cardholder']!=null}checked="checked"{/if}></label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="monei_primary_color">
                            Primary colour
                        </label>
                        <div class="col-lg-9">
                            <input type="text" name="monei_primary_color" id="monei_primary_color">
                            <p class="help-block">
                                A color for checkout and submit button. Default: #00796b
                            </p>
                        </div>
                    </div>
                    <div class="panel-footer">
                        <button type="submit" value="1" id="module_form_submit_btn" name="btnSubmit"
                                class="btn btn-default pull-right">
                            <i class="process-icon-save"></i> Save
                        </button>
                    </div>
                </div>
        </form>

    </div>
</div>
