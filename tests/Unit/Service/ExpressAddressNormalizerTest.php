<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PsMonei\Service\Express\ExpressAddressNormalizer;

/**
 * PayPal returns a partial address when the PayPal account has none saved: name,
 * email and country, with no street. PrestaShop validates addresses strictly, so
 * the payment would fail after the shopper had already approved it.
 */
class ExpressAddressNormalizerTest extends TestCase
{
    public function testAFullAddressPassesThroughUnchanged(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'zip' => '28013',
            'country' => 'ES',
            'phone' => '600000000',
        ]);

        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Lovelace', $result['lastName']);
        $this->assertSame('Calle Mayor 1', $result['address1']);
        $this->assertSame('Madrid', $result['city']);
        $this->assertSame('28013', $result['postcode']);
        $this->assertSame('ES', $result['countryIso']);
        $this->assertFalse($result['incomplete']);
    }

    public function testAPartialPayPalAddressIsFilledAndFlagged(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'country' => 'ES',
        ]);

        $this->assertTrue($result['incomplete'], 'the merchant has to be able to see this needs completing');
        $this->assertSame(ExpressAddressNormalizer::PLACEHOLDER, $result['address1']);
        $this->assertSame(ExpressAddressNormalizer::PLACEHOLDER, $result['city']);
        $this->assertSame('ES', $result['countryIso']);
        // Names survive: PayPal does return those.
        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Lovelace', $result['lastName']);
    }

    public function testWalletsThatSpellTheFieldsDifferentlyAreUnderstood(): void
    {
        // Apple Pay, Google Pay and PayPal disagree on every one of these names.
        $result = ExpressAddressNormalizer::normalize([
            'fullName' => 'Ada Lovelace',
            'addressLine1' => 'Calle Mayor 1',
            'locality' => 'Madrid',
            'postalCode' => '28013',
            'countryCode' => 'es',
            'phoneNumber' => '600000000',
        ]);

        $this->assertSame('Calle Mayor 1', $result['address1']);
        $this->assertSame('Madrid', $result['city']);
        $this->assertSame('28013', $result['postcode']);
        $this->assertSame('ES', $result['countryIso'], 'the country code is normalised to upper case');
        $this->assertSame('600000000', $result['phone']);
        $this->assertFalse($result['incomplete']);
    }

    public function testASingleWordNameStillFillsBothRequiredFields(): void
    {
        $result = ExpressAddressNormalizer::normalize(['name' => 'Ada', 'country' => 'ES']);

        $this->assertSame('Ada', $result['firstName']);
        $this->assertSame('Ada', $result['lastName']);
    }

    public function testAMultiWordSurnameIsKeptTogether(): void
    {
        $this->assertSame(
            ['Ada', 'King Lovelace'],
            ExpressAddressNormalizer::splitName('Ada King Lovelace')
        );
    }

    public function testAMissingPhoneDoesNotMakeTheAddressIncomplete(): void
    {
        $result = ExpressAddressNormalizer::normalize([
            'name' => 'Ada Lovelace',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'zip' => '28013',
            'country' => 'ES',
        ]);

        $this->assertSame('', $result['phone']);
        $this->assertFalse($result['incomplete'], 'a phone number is not required by PrestaShop');
    }

    public function testAnEmptyPayloadIsHandled(): void
    {
        $result = ExpressAddressNormalizer::normalize([]);

        $this->assertTrue($result['incomplete']);
        $this->assertSame(ExpressAddressNormalizer::PLACEHOLDER, $result['firstName']);
        $this->assertSame('', $result['countryIso']);
    }
}
