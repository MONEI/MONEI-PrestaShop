<form id="configuration_form" class="defaultForm form-horizontal MoneiPaymentPlatform"
      action="index.php?controller=AdminModules&amp;configure=moneipaymentplatform&amp;token={{$token}}"
      method="post" enctype="multipart/form-data" novalidate="">
    <input type="hidden" name="submitMoneiPaymentPlatform" value="1">

    <div class="oppcw-backend-form" id="moneiConfigForm">

        <div class="panel">
            <div class="panel-heading">
                <i class="icon-envelope"></i> {{$heading}}
            </div>
            <div class="form-wrapper">
                <div class="form-group">
                    <div class="col-lg-12">
                        <div class="testMode">
                            <label for="operationMode_testMode">Test Mode: <input type="checkbox"
                                                                                  name="operationMode_testMode"
                                                                                  id="operationMode_testMode" {if $defaultValues['operationMode_testMode']!=null}checked="checked"{/if}></label>
                        </div>
                        <p class="help-block">
                            Test Mode allows you to test the functionality of your payment gateway without any money
                            changing hands. For testing you can use <a
                                    href="https://docs.monei.net/reference/parameters#test-accounts" target="_blank">Credit
                                Card Test Accounts</a> or any valid credit card.
                        </p>
                    </div>
                </div>

                <div class="form-group paymentMethods {if $isSubmitted && $defaultValues['acceptedPayment_visa']==null && $defaultValues['acceptedPayment_mastercard']==null && $defaultValues['acceptedPayment_maestro']==null && $defaultValues['acceptedPayment_jcb']==null} has-error{/if}">
                    <span class="creditcardHeader">Select Payment Methods:</span>
                    <span class="creditcardCheckbox">
                        <input type="checkbox" name="acceptedPayment_visa" id="acceptedPayment_visa" {if $defaultValues['acceptedPayment_visa']!=null}checked="checked"{/if}>
                        <span class="moneiIcon visa"></span>
                    </span>
                    <span class="creditcardCheckbox">
                        <input type="checkbox" name="acceptedPayment_mastercard" id="acceptedPayment_mastercard" {if $defaultValues['acceptedPayment_mastercard']!=null}checked="checked"{/if}>
                        <span class="moneiIcon mastercard"></span>
                    </span>
                    <span class="creditcardCheckbox">
                        <input type="checkbox" name="acceptedPayment_maestro" id="acceptedPayment_maestro" {if $defaultValues['acceptedPayment_maestro']!=null}checked="checked"{/if}>
                        <span class="moneiIcon maestro"></span>
                    </span>
                    <span class="creditcardCheckbox">
                        <input type="checkbox" name="acceptedPayment_jcb" id="acceptedPayment_jcb" {if $defaultValues['acceptedPayment_jcb']!=null}checked="checked"{/if}>
                        <span class="moneiIcon jcb"></span>
                    </span>

                    <p class="help-block">

                    </p>
                </div>
            </div>
            <div class="form-group moneiData">

                <div class="col-lg-12">
                    <div class="">Please provide your <a href="https://monei.net/"
                                                         target="_blank">MONEI</a> credentials:
                    </div>
                    <div class="moneiDataField {if $isSubmitted && $defaultValues['moneiData_AppID']==null} has-error{/if}">
                        <label for="moneiData_AppID">App ID</label>
                        <input class="form-control" type="text" name="moneiData_AppID" id="moneiData_AppID" {if $defaultValues['moneiData_AppID']!=null}value="{{$defaultValues['moneiData_AppID']}}"{/if}>
                    </div>

                    <div class="moneiDataField {if $isSubmitted && $defaultValues['moneiData_ChannelID']==null} has-error{/if}">
                        <label for="moneiData_ChannelID">Channel ID</label>
                        <input class="form-control" type="text" name="moneiData_ChannelID" {if $defaultValues['moneiData_ChannelID']!=null}value="{{$defaultValues['moneiData_ChannelID']}}"{/if}
                               id="moneiData_ChannelID">
                    </div>

                    <div class="moneiDataField {if $isSubmitted && $defaultValues['moneiData_UserID']==null} has-error{/if}" >
                        <label for="moneiData_UserID">User ID</label>
                        <input class="form-control" type="text" name="moneiData_UserID" id="moneiData_UserID" {if $defaultValues['moneiData_UserID']!=null}value="{{$defaultValues['moneiData_UserID']}}"{/if}>
                    </div>

                    <div class="moneiDataField {if $isSubmitted && $defaultValues['moneiData_Password']==null} has-error{/if}">
                        <label for="moneiData_Password">Password</label>
                        <input class="form-control" type="password" name="moneiData_Password" id="moneiData_Password" {if $defaultValues['moneiData_Password']!=null}value="{{$defaultValues['moneiData_Password']}}"{/if}>
                    </div>
                    <p class="help-block">

                    </p>
                </div>
            </div>
            <div class="panel-footer">
                <button type="submit" value="1" id="configuration_form_submit_btn" name="submitMoneiPaymentPlatform"
                        class="pull-left">
                    <i class="process-icon-save"></i> Save
                </button>
            </div>
        </div>

    </div><!-- /.form-wrapper -->
</form>
