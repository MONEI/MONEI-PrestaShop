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
        $start = strpos($content, "Tools::isSubmit('$submit')");
        $this->assertNotFalse($start, "$submit branch not found");
        $end = strpos($content, '}elseif(Tools::isSubmit(', $start);
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
            '/\{if \$monei_connect\.canConnect\}\s*<form[^>]*>\s*<input type="hidden" name="monei_connect_token"[^>]*>\s*<button type="submit" name="submitMoneiConnect"/',
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
        $branch = $this->branch('submitMoneiConnect');

        $this->assertBefore('if(!$this->hasConnectFormToken()){', '->begin(', $branch, 'token before the state write');
        $this->assertSame(1, substr_count($this->code(self::MODULE), '->begin('));
        $this->assertStringContainsString(
            'returnhash_equals($this->connectFormToken(),(string)Tools::getValue(\'monei_connect_token\'));',
            $this->method(self::MODULE, 'hasConnectFormToken')
        );
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
        $branch = $this->branch('submitMoneiDisconnect');

        $this->assertBefore('if(!$this->hasConnectFormToken()){', '->disconnect(', $branch, 'token before the clear');
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
