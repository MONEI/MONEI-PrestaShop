<div class="row">
    <div class="col-lg-12">
        <div class="alert alert-info">
            <p><b>MONEI Payment Gateway</b> is the easiest way to accept payments from your customers.</p>
            <p>
                To use this payment method you need to be registered in
                <a href="https://monei.net/" target="_blank">MONEI Dashboard</a>
            </p>
        </div>
        <form id="configuration_form" class="form-horizontal moneiConfigForm"
              action="index.php?controller=AdminModules&amp;configure=moneipaymentplatform&amp;token={{$token}}"
              method="post" enctype="multipart/form-data" novalidate="">
            <input type="hidden" name="submitmoneipaymentplatform" value="1">

            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-money"></i> Payment settings
                </div>
                <div class="form-wrapper">
                    <div class="form-group">
                        <label class="control-label col-lg-3 required" for="token">
                            Secret Token
                        </label>
                        <div class="col-lg-9">
                            <input type="text" name="secret_token" id="secret_token" required="required"
                                   value="{{$values['secret_token']}}">
                            <p class="help-block">
                                Secret token generated for your sub account in
                                <a href="https://monei.net/" target="_blank">MONEI Dashboard</a>
                            </p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-lg-3 required" for="brands">
                            Payment methods
                        </label>
                        <div class="col-lg-9">
                            <select multiple="multiple" class="bootstrap" name="brands[]" id="brands">
                                {foreach $supportedBrands as $value=>$label}
                                    <option {if in_array($value, $values['brands'])}selected=""{/if}
                                            value="{{$value}}">{{$label}}</option>
                                {/foreach}
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="descriptor">
                            Descriptor
                        </label>
                        <div class="col-lg-9">
                            <input type="text" name="descriptor" id="descriptor" value="{{$values['descriptor']}}">
                            <p class="help-block">
                                Descriptor that will be shown in customer's bank statement
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
            </div>
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-eye"></i> Appearance settings
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3" for="title">
                        Title
                    </label>
                    <div class="col-lg-9">
                        <input type="text" name="title" id="title" value="{{$values['title']}}"
                               placeholder="Pay with Credit Card">
                        <p class="help-block">
                            Title of payment method which the user sees during checkout.
                        </p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3" for="description">
                        Description
                    </label>
                    <div class="col-lg-9">
                        <input type="text" name="description" id="description" value="{{$values['description']}}"
                               placeholder="Pay via MONEI Payment Gateway.">
                        <p class="help-block">
                            Description of payment method which the user sees during checkout.
                        </p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3" for="submit_text">
                        Submit text
                    </label>
                    <div class="col-lg-9">
                        <input type="text" name="submit_text" id="submit_text" value="{{$values['submit_text']}}"
                               placeholder="Pay now">
                        <p class="help-block">
                            Submit button text, &#123;amount&#125; will be replaced with amount value with currency.
                        </p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3" for="show_cardholder">
                        Show carholder field
                    </label>
                    <div class="col-lg-9">
                        <input type="checkbox" name="show_cardholder" id="show_cardholder"
                               {if $values['show_cardholder']!=null}checked="checked"{/if}></label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3" for="primary_color">
                        Primary colour
                    </label>
                    <div class="col-lg-9">
                        <input type="text" name="primary_color" id="primary_color" value="{{$values['primary_color']}}"
                               placeholder="#00796b">
                        <p class="help-block">
                            A color for checkout and submit button.
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
