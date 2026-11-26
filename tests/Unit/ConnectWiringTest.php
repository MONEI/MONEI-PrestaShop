<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The PrestaShop wiring of "Connect with MONEI" and of the callback verify.
 *
 * ConnectServiceTest and CallbackVerifierTest cover the rules. These source tests
 * catch wiring that skips them: a branch without its token check, a check after the
 * write, a callback that processes the request body. connect.spec.js covers the
 * same routes on a running shop. Every test names the bug it catches.
 */
class ConnectWiringTest extends TestCase
{
    use SourceCode;

    private const ROOT = __DIR__ . '/../..';
    private const MODULE = 'monei.php';
    private const VALIDATION = 'controllers/front/validation.php';
    private const CALLBACK = 'controllers/front/connect.php';

    /**
     * Comment- and whitespace-free source of a file.
     */
    private function code(string $file): string
    {
        return (string) preg_replace('/\s+/', '', $this->codeWithoutComments(self::ROOT . '/' . $file));
    }

    /**
     * Body of a method, up to the next method.
     */
    private function method(string $file, string $name): string
    {
        $code = $this->code($file);
        $start = strpos($code, 'function' . $name . '(');
        $this->assertNotFalse($start, "$name not found in $file");
        $end = preg_match('/(public|private|protected)(static)?function/', $code, $match, PREG_OFFSET_CAPTURE, $start + 1) ? $match[0][1] : strlen($code);

        return substr($code, $start, $end - $start);
    }

    /**
     * A branch of getContent, up to the next branch.
     */
    private function branch(string $submit): string
    {
        $content = $this->method(self::MODULE, 'getContent');
        // The branch that acts, not the permission branch before it, which names it too.
        $start = strpos($content, "}elseif(Tools::isSubmit('$submit')");
        $this->assertNotFalse($start, "$submit branch not found");
        $end = strpos($content, '}elseif(Tools::isSubmit(', $start + 1);
        $this->assertNotFalse($end, "$submit is not followed by another branch");

        return substr($content, $start, $end - $start);
    }

    private function assertBefore(string $first, string $then, string $haystack, string $message): void
    {
        $firstAt = strpos($haystack, $first);
        $thenAt = strpos($haystack, $then);
        $this->assertNotFalse($firstAt, "$message: $first not found");
        $this->assertNotFalse($thenAt, "$message: $then not found");
        $this->assertLessThan($thenAt, $firstAt, $message);
    }

    /**
     * Bug: the order changes from the request body instead of the payment the verifier
     * proved or fetched, or validation keeps its own v1 check beside the verifier.
     */
    public function testValidationProcessesOnlyTheVerifierPayment(): void
    {
        $code = $this->code(self::VALIDATION);

        $this->assertStringContainsString('$decision=$verifier->verify($requestBody,$signature);', $code);
        $this->assertStringContainsString('if($decision[\'payment\']===null){', $code);
        $this->assertStringContainsString('->createOrUpdateOrder($payment->getId(),false,$payment);', $code);
        $this->assertStringNotContainsString('verifySignature(', $code);
        $this->assertStringNotContainsString('json_decode(', $code);
        $this->assertStringNotContainsString('newPayment(', $code);
    }

    /**
     * Bug: a connected shop refuses a callback without MONEI-Signature (an account
     * whose v2 read failed sends none), or a manual shop stops refusing it.
     */
    public function testTheMissingSignature401AppliesOnlyWithoutAScopedKey(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\(!isset\(\$_SERVER\[\'HTTP_MONEI_SIGNATURE\'\]\)&&!\$verifier->anyModeConnected\(\)\)\{[^}]*http_response_code\(401\)/',
            $this->code(self::VALIDATION)
        );
    }

    /**
     * Bug: a callback is fetched a second time past the verifier's checks, or the
     * redirect paths lose their own fetch.
     */
    public function testCreateOrUpdateOrderFetchesOnlyWithoutAGivenPayment(): void
    {
        $this->assertStringContainsString(
            '$moneiPayment=$verifiedPayment??$this->moneiService->getMoneiPayment($moneiPaymentId);',
            $this->method('src/Service/Order/OrderService.php', 'createOrUpdateOrder')
        );
        // Nothing createOrUpdateOrder calls fetches again, the payment method display included.
        $this->assertSame(1, substr_count($this->code('src/Service/Order/OrderService.php'), 'getMoneiPayment('));
    }

    /**
     * Bug: a connect started on one shop writes the row of the shop the callback lands
     * on, or of no shop.
     */
    public function testTheStoreReadsAndWritesAtTheGivenScope(): void
    {
        $code = $this->code('src/Repository/ConfigurationStore.php');

        $this->assertStringContainsString('\Configuration::updateValue($key,$value,false,$idShopGroup,$idShop);', $code);
        $this->assertStringContainsString('\Configuration::get($key,null,$idShopGroup,$idShop)', $code);
    }

    /**
     * Bug: the form shows the button for a callback URL the broker refuses (an http
     * shop), or the start sends another URL than the form checked.
     */
    public function testTheFormAndTheStartShareTheCheckedCallbackUrl(): void
    {
        $this->assertStringContainsString(
            'try{$uri=Connect::canonicalRedirectUri($this->context->link->getModuleLink($this->name,\'connect\'));}catch(InvalidArgumentException$e){returnnull;}returnConnect::callbackProblem(ConnectService::CLIENT,$uri)===null?$uri:null;',
            $this->method(self::MODULE, 'connectRedirectUri')
        );
        $this->assertStringContainsString('($redirectUri=$this->connectRedirectUri())===null', $this->branch('submitMoneiConnect'));
        $this->assertStringContainsString('\'canConnect\'=>$this->connectRedirectUri()!==null', $this->method(self::MODULE, 'connectPanel'));
        $this->assertMatchesRegularExpression(
            '/\{if \$monei_connect\.canConnect\}\s*<form[^>]*>\s*<input type="hidden" name="monei_form_token"[^>]*>\s*<button type="submit" name="submitMoneiConnect"/',
            (string) file_get_contents(self::ROOT . '/views/templates/admin/configure.tpl')
        );
    }

    /**
     * Bug: a state is stored by a request that is not an admin's of this shop (PS 9
     * runs getContent for a wrong token), then consented with an attacker's MONEI
     * account; or the public callback route can start a connect.
     */
    public function testOnlyTheStartBranchStoresAStateAfterTheToken(): void
    {
        // FormTokenWiringTest pins every submit of getContent to the form token check.
        $this->assertBefore('if($this->isFormSubmitted()&&!$this->hasFormToken()){', "Tools::isSubmit('submitMoneiConnect')", $this->method(self::MODULE, 'getContent'), 'token before the state write');
        $this->assertStringContainsString('->begin(', $this->branch('submitMoneiConnect'));
        $this->assertSame(1, substr_count($this->code(self::MODULE), '->begin('));
        foreach (glob(self::ROOT . '/controllers/front/*.php') as $file) {
            $code = $this->code(substr($file, strlen(self::ROOT) + 1));
            $this->assertStringNotContainsString('->begin(', $code, $file);
            $this->assertStringNotContainsString('Connect::start(', $code, $file);
        }
    }

    /**
     * Bug: a forged disconnect from a signed-in admin's browser clears the credentials
     * and stops checkout.
     */
    public function testOnlyTheDisconnectBranchClearsAfterTheToken(): void
    {
        $this->assertBefore('if($this->isFormSubmitted()&&!$this->hasFormToken()){', "Tools::isSubmit('submitMoneiDisconnect')", $this->method(self::MODULE, 'getContent'), 'token before the clear');
        $this->assertStringContainsString('->disconnect(', $this->branch('submitMoneiDisconnect'));
        $this->assertSame(1, substr_count($this->code(self::MODULE), '->disconnect('));
        foreach (glob(self::ROOT . '/controllers/front/*.php') as $file) {
            $this->assertStringNotContainsString('->disconnect(', $this->code(substr($file, strlen(self::ROOT) + 1)), $file);
        }
    }

    /**
     * Bug: the code in the callback URL leaves in a Referer from the result page.
     */
    public function testTheCallbackSendsNoReferrerFirst(): void
    {
        $this->assertStringStartsWith('functionpostProcess(){header(\'Referrer-Policy:no-referrer\');', $this->method(self::CALLBACK, 'postProcess'));
    }

    /**
     * Bug: a new key is tested on the client of the stored key, or a working switch is
     * lost to an Apple Pay error, or Apple Pay registers with the old key.
     */
    public function testTheSwitchTestsAndRegistersOnAFreshClientOfTheNewKey(): void
    {
        $this->assertStringContainsString(
            'MoneiClientFactory::create($apiKey,MoneiService::getUserAgent())->paymentMethods->getAllowed();',
            $this->method(self::MODULE, 'connectService')
        );
        $this->assertMatchesRegularExpression(
            '/try\{MoneiClientFactory::create\(\$keys\[\'apiKey\'\],MoneiService::getUserAgent\(\)\)->applePayDomain->register\(\$request\);\}catch\(Throwable\$e\)/',
            $this->method(self::MODULE, 'afterConnectSwitch')
        );
        $this->assertBefore('->complete(', '->afterConnectSwitch(', $this->code(self::CALLBACK), 'store before Apple Pay');
    }

    /**
     * Bug: a manual key change keeps the signing secret of the replaced key.
     */
    public function testTheSettingsSaveRunsTheSecretRule(): void
    {
        $save = $this->method(self::MODULE, 'postProcess');

        $this->assertBefore('$keysBefore=$connect->apiKeys(...$scope);', 'Configuration::updateValue($configKey,$value);', $save, 'keys read before the save');
        $this->assertBefore('Configuration::updateValue($configKey,$value);', '$connect->forgetSecretsOfChangedKeys($keysBefore,...$scope);', $save, 'rule after the save');
    }

    /**
     * Bug: a settings tab rendered before a connect or a disconnect writes the old
     * keys and mode back over the new ones, or calls MONEI with them; or the form loses
     * the fingerprints, so every save looks stale.
     */
    public function testAStaleSettingsSaveKeepsTheKeysAndTheMode(): void
    {
        $save = $this->method(self::MODULE, 'postProcess');

        $this->assertMatchesRegularExpression(
            '/if\(\$this->connectService\(\)->staleModes\(\[[^]]*\],\.\.\.\$this->shopScope\(\)\)\)\{unset\(\$form_values\[\'MONEI_PRODUCTION_MODE\'\]\);foreach\(ConnectService::KEYSas\$keys\)\{unset\(\$form_values\[\$keys\[\'accountId\'\]\],\$form_values\[\$keys\[\'apiKey\'\]\]\);\}[^}]*break;\}/',
            $save
        );
        $this->assertBefore('->staleModes(', '$this->validateApiCredentials($form_values)', $save, 'a stale save skips the check that calls MONEI');
        $this->assertStringContainsString('\'fields_value\'=>$this->settingsFormValues(),', $this->method(self::MODULE, 'renderForm'));
        $this->assertStringContainsString('return$values+$this->keyFingerprintValues();', $this->method(self::MODULE, 'settingsFormValues'));
    }

    /**
     * Bug: the settings page prints a connected key in full, or an empty key field
     * (the form never shows a connected key) wipes the key on save or fails the
     * credential check.
     */
    public function testAConnectedKeyIsNeverRenderedAndAnEmptyFieldKeepsIt(): void
    {
        $this->assertStringContainsString(
            'if(strpos((string)$values[$keys[\'apiKey\']],\'mk_\')===0){$values[$keys[\'apiKey\']]=\'\';}',
            $this->method(self::MODULE, 'settingsFormValues')
        );
        $this->assertStringContainsString(
            'if((string)Tools::getValue($keys[\'apiKey\'])===\'\'&&$this->submittedApiKey($keys[\'apiKey\'])!==\'\'){unset($form_values[$keys[\'apiKey\']]);}',
            $this->method(self::MODULE, 'postProcess')
        );
        $validate = $this->method(self::MODULE, 'validateApiCredentials');
        $this->assertStringContainsString('$apiKey=$this->submittedApiKey(\'MONEI_API_KEY\');', $validate);
        $this->assertStringContainsString('$testApiKey=$this->submittedApiKey(\'MONEI_TEST_API_KEY\');', $validate);
    }

    /**
     * Bug: the callback log holds the customer and card data of a payment.
     */
    public function testTheCallbackLogsAnAllowlistOfThePayment(): void
    {
        preg_match_all('/\$payment->get(\w+)\(\)/', (string) strstr($this->code(self::VALIDATION), 'Monei::logDebug(\'[MONEI]Webhookreceived'), $getters);

        $this->assertSame(
            ['Id', 'OrderId', 'Status', 'StatusCode', 'Amount', 'Currency', 'Livemode'],
            array_slice($getters[1], 0, 7)
        );
    }

    /**
     * Bug: an AUTHORIZED or SUCCEEDED payment for another amount marks the order
     * authorized or paid. The fetch path relies on this check in createOrUpdateOrder:
     * it runs for every status.
     */
    public function testTheAmountCheckOfAnOrderRunsForEveryStatus(): void
    {
        $orders = 'src/Service/Order/OrderService.php';

        $this->assertStringContainsString('if(!$this->paymentMatchesOrder($order,$moneiPayment)){return;}', $this->method($orders, 'updateExistingOrder'));
        $this->assertStringContainsString('if(!$paymentMatchesCart){', $this->method($orders, 'createNewOrder'));
        $this->assertStringContainsString('$orderStateId=(int)\Configuration::get(\'MONEI_STATUS_FAILED\');', $this->method($orders, 'createNewOrder'));
        $this->assertStringNotContainsString('getStatus()===', $this->method($orders, 'paymentMatchesOrder'));
    }

    /**
     * Bug: a shop that cannot connect (an http shop) is invited to connect anyway.
     */
    public function testTheNoticeShowsOnlyWhereConnectCanShow(): void
    {
        $this->assertStringContainsString('||$this->connectRedirectUri()===null){returnfalse;}', $this->method(self::MODULE, 'shouldInviteToConnect'));
    }

    /**
     * Bug: a capture, void, refund or renewal changes a payment at MONEI without
     * writing its monei2_payment status. The fetch path takes only a forward move from
     * that status, so a stale AUTHORIZED lets an unsigned replay of SUCCEEDED through.
     * A new kind of change fails this test until its row write is added here.
     */
    public function testEveryChangeOfAPaymentWritesItsRowStatus(): void
    {
        $calls = [];
        $files = [self::ROOT . '/monei.php'];
        foreach (['src', 'controllers', 'upgrade'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach ($files as $file) {
            preg_match_all('/->(payments->(?:capture|cancel|refund|create|confirm|recurring)|subscriptions->\w+)\(/', $this->code(substr($file, strlen(self::ROOT) + 1)), $found);
            foreach ($found[1] as $call) {
                $calls[] = basename($file) . ' ' . $call;
            }
        }
        sort($calls);

        $this->assertSame([
            'MoneiService.php payments->capture',
            'MoneiService.php payments->create',
            'MoneiService.php payments->create',
            'MoneiService.php payments->refund',
        ], $calls);
        $service = 'src/Service/Monei/MoneiService.php';
        $this->assertBefore('->payments->capture(', '$moneiPayment->setStatus($capturedPayment->getStatus());', $this->method($service, 'capturePayment'), 'capture writes the status');
        $this->assertBefore('$moneiPayment->setStatus($capturedPayment->getStatus());', '$this->moneiPaymentRepository->save($moneiPayment);', $this->method($service, 'capturePayment'), 'capture saves the row');
        $this->assertBefore('->payments->refund(', '$this->saveMoneiPayment($moneiPayment,$orderId,$employeeId);', $this->method($service, 'createRefund'), 'refund saves the row');
        $this->assertStringContainsString('$this->saveMoneiPayment($moneiPaymentResponse);', $this->method($service, 'createMoneiPayment'));
    }

    /**
     * Bug: a refused connect hides what to do: cap must say to revoke unused keys,
     * not_approved that Live comes after approval and the shop stays in test mode.
     */
    public function testTheCapAndNotApprovedMessagesSayWhatToDo(): void
    {
        $messages = $this->codeWithoutComments(self::ROOT . '/' . self::MODULE);

        $this->assertMatchesRegularExpression("/'cap' => \\\$this->l\\('[^']*Revoke unused keys in the MONEI Dashboard/", $messages);
        $this->assertMatchesRegularExpression("/'not_approved' => \\\$this->l\\('Live keys come after MONEI approves your account\\. The shop stays in test mode\\.'\\)/", $messages);
    }
}
