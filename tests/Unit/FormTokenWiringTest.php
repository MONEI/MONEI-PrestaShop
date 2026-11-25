<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The token of the module configuration forms. PrestaShop 9 runs getContent for a
 * wrong or missing admin token, so this token is the only CSRF check of the
 * settings. settings-token.spec.js covers it on a running shop.
 */
class FormTokenWiringTest extends TestCase
{
    use SourceCode;

    private const MODULE = __DIR__ . '/../../monei.php';

    private function method(string $name): string
    {
        $code = (string) preg_replace('/\s+/', '', $this->codeWithoutComments(self::MODULE));
        $start = strpos($code, 'function' . $name . '(');
        $this->assertNotFalse($start, "$name not found");
        $end = preg_match('/(public|private|protected)(static)?function/', $code, $match, PREG_OFFSET_CAPTURE, $start + 1) ? $match[0][1] : strlen($code);

        return substr($code, $start, $end - $start);
    }

    /**
     * Bug: a cross-site POST changes the settings (the API keys among them), or a new
     * form's submit is dispatched without being in the list the token check reads.
     */
    public function testEverySubmitOfGetContentNeedsTheToken(): void
    {
        $content = $this->method('getContent');
        preg_match_all("/Tools::isSubmit\\('(\\w+)'\\)/", $content, $dispatched);
        preg_match('/constFORM_SUBMITS=\\[([^\\]]*)\\]/', (string) preg_replace('/\s+/', '', $this->codeWithoutComments(self::MODULE)), $list);

        $this->assertStringContainsString('if($this->isFormSubmitted()&&!$this->hasFormToken()){', $content);
        $this->assertLessThan(strpos($content, 'Tools::isSubmit('), strpos($content, '$this->isFormSubmitted()'));
        $this->assertSame(
            ['submitMoneiModule', 'submitMoneiModuleGateways', 'submitMoneiModuleStatus', 'submitMoneiModuleComponentStyle', 'submitMoneiModuleExpress'],
            $dispatched[1]
        );
        $this->assertSame("'" . implode("','", $dispatched[1]) . "',", $list[1]);
        $this->assertStringContainsString("returnhash_equals(\$this->formToken(),(string)Tools::getValue('monei_form_token'));", $this->method('hasFormToken'));
    }

    /**
     * Bug: a form renders without the token, so every save of it is refused.
     */
    public function testEveryFormRendersWithTheToken(): void
    {
        $code = (string) preg_replace('/\s+/', '', $this->codeWithoutComments(self::MODULE));

        $this->assertSame(5, substr_count($code, 'return$this->generateFormWithToken($helper,$this->getConfigForm'));
        $this->assertSame(1, substr_count($code, '$helper->generateForm('));
        $this->assertStringContainsString('$helper->generateForm([$form]);', $this->method('generateFormWithToken'));
    }
}
